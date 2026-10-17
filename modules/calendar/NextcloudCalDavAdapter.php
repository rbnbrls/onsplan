<?php

namespace Modules\Calendar;

use DateTimeImmutable;
use Illuminate\Support\Str;
use Sabre\DAV\Client;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;
use RuntimeException;

final class NextcloudCalDavAdapter implements CalendarProviderAdapter
{
    private Client $client;

    public function __construct(private CalendarIntegration $integration)
    {
        $this->client = new Client([
            'baseUri' => rtrim((string) $integration->endpoint_url, '/').'/',
            'userName' => $integration->username,
            'password' => $integration->password,
            'authType' => Client::AUTH_BASIC,
        ]);
    }

    public function discoverCalendars(): array
    {
        $props = $this->client->propFind('/', [
            '{DAV:}current-user-principal',
            '{DAV:}principal-URL',
        ], 0);
        $principal = $this->href($props['{DAV:}current-user-principal']['{DAV:}href'] ?? $props['{DAV:}principal-URL']['{DAV:}href'] ?? null);
        if (! $principal) throw new RuntimeException('Nextcloud did not return a CalDAV principal.');

        $principalProps = $this->client->propFind($principal, ['{urn:ietf:params:xml:ns:caldav}calendar-home-set'], 0);
        $home = $this->href($principalProps['{urn:ietf:params:xml:ns:caldav}calendar-home-set']['{DAV:}href'] ?? null);
        if (! $home) throw new RuntimeException('Nextcloud did not return a calendar home.');

        $rows = $this->client->propFind($home, [
            '{DAV:}displayname',
            '{DAV:}resourcetype',
            '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set',
            '{http://calendarserver.org/ns/}getctag',
            '{DAV:}sync-token',
            '{DAV:}current-user-privilege-set',
        ], 1);
        $calendars = [];
        foreach ($rows as $href => $row) {
            $type = $row['{DAV:}resourcetype'] ?? [];
            $isCalendar = isset($type['{urn:ietf:params:xml:ns:caldav}calendar']);
            $components = $row['{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set'] ?? null;
            if (! $isCalendar || ($components && ! str_contains(json_encode($components), 'VEVENT'))) continue;
            $privileges = $row['{DAV:}current-user-privilege-set'] ?? null;
            if ($privileges && ! preg_match('/write-(?:content|properties)|\bwrite\b/i', json_encode($privileges))) continue;
            $calendars[] = [
                'id' => $this->href($href),
                'name' => (string) ($row['{DAV:}displayname'] ?? basename(trim($href, '/'))),
                'writable' => true,
                'href' => $this->href($href),
                'principal_url' => $principal,
                'calendar_home_url' => $home,
            ];
        }

        return $calendars;
    }

    public function pullChanges(?string $cursor): array
    {
        $body = $cursor
            ? '<?xml version="1.0"?><d:sync-collection xmlns:d="DAV:"><d:sync-token>'.htmlspecialchars($cursor, ENT_XML1).'</d:sync-token><d:prop><d:getetag/><d:resourcetype/><c:calendar-data xmlns:c="urn:ietf:params:xml:ns:caldav"/></d:prop><d:sync-level>1</d:sync-level></d:sync-collection>'
            : '<?xml version="1.0"?><c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><d:getetag/><c:calendar-data/></d:prop><c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VEVENT"/></c:comp-filter></c:filter></c:calendar-query>';
        $response = $this->client->request('REPORT', $this->integration->calendar_id, $body, [
            'Depth' => '1', 'Content-Type' => 'application/xml; charset=utf-8',
        ]);
        if ($cursor && ($response['statusCode'] ?? 200) >= 400) {
            // A server may expire a sync-token. Reconcile from the complete calendar and let the
            // caller replace the cursor with the fresh token returned by this report.
            $body = '<?xml version="1.0"?><c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><d:getetag/><c:calendar-data/></d:prop><c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VEVENT"/></c:comp-filter></c:filter></c:calendar-query>';
            $response = $this->client->request('REPORT', $this->integration->calendar_id, $body, [
                'Depth' => '1', 'Content-Type' => 'application/xml; charset=utf-8',
            ]);
        }
        $changes = $this->parseMultiStatus($response['body']);
        return ['changes' => $changes, 'cursor' => $this->extractSyncToken($response['body'])];
    }

    public function create(CalendarEvent $event): array
    {
        $uid = 'onsplan-'.Str::uuid().'.ics';
        return $this->write($uid, $this->ics($event, substr($uid, 0, -4)), null) + ['uid' => substr($uid, 0, -4)];
    }

    public function update(CalendarEventSync $sync, CalendarEvent $event): array
    {
        $href = $sync->external_href ?: $sync->external_event_id;
        $filename = parse_url($href, PHP_URL_PATH) ? basename(parse_url($href, PHP_URL_PATH)) : basename($href);
        return $this->write($filename, $this->ics($event, $sync->external_ical_uid ?: pathinfo($filename, PATHINFO_FILENAME)), $sync->etag) + ['uid' => $sync->external_ical_uid];
    }

    public function delete(CalendarEventSync $sync): void
    {
        $response = $this->client->request('DELETE', $sync->external_href ?: $sync->external_event_id, null, $sync->etag ? ['If-Match' => $sync->etag] : []);
        if ($response['statusCode'] >= 300) throw new RuntimeException('Nextcloud refused the event deletion.');
    }

    /** @return array<string, mixed> */
    public function parse(array $change): array
    {
        if (($change['status'] ?? 200) === 404 || ($change['deleted'] ?? false)) return ['deleted' => true];
        $calendar = Reader::read($change['data'] ?? '');
        $event = $calendar->VEVENT;
        if (! $event) throw new RuntimeException('Nextcloud returned an iCalendar without VEVENT.');
        $start = $event->DTSTART->getDateTime();
        $end = $event->DTEND?->getDateTime() ?: $start;
        $allDay = ($event->DTSTART['VALUE'] ?? null) === 'DATE';
        if ($allDay) $end = $end->modify('-1 day');
        $rrule = (string) ($event->RRULE ?? '');
        [$repeat, $interval, $until] = $this->recurrence($rrule);
        return [
            'uid' => (string) ($event->UID ?? pathinfo($change['href'], PATHINFO_FILENAME)),
            'href' => $change['href'], 'etag' => $change['etag'] ?? null,
            'title' => (string) ($event->SUMMARY ?? '(Untitled)'),
            'notes' => strip_tags((string) ($event->DESCRIPTION ?? '')),
            'start_date' => $start->format('Y-m-d'), 'end_date' => $end->format('Y-m-d'),
            'start_time' => $allDay ? null : $start->format('H:i'), 'end_time' => $allDay ? null : $end->format('H:i'),
            'repeat' => $repeat, 'repeat_interval' => $interval, 'repeat_until' => $until,
            'raw_recurrence' => $rrule ?: null,
        ];
    }

    private function write(string $filename, string $body, ?string $etag): array
    {
        $response = $this->client->request('PUT', $filename, $body, array_filter([
            'Content-Type' => 'text/calendar; charset=utf-8', 'If-None-Match' => $etag ? null : '*', 'If-Match' => $etag,
        ]));
        return ['href' => $this->client->getAbsoluteUrl($filename), 'etag' => $response['headers']['etag'][0] ?? $response['headers']['etag'] ?? null];
    }

    private function ics(CalendarEvent $event, string $uid): string
    {
        $calendar = new VCalendar();
        $vevent = $calendar->createComponent('VEVENT');
        $vevent->add('UID', $uid);
        $vevent->add('SUMMARY', $event->title);
        if ($event->notes) $vevent->add('DESCRIPTION', $event->notes);
        if (! $event->start_time) {
            $vevent->add('DTSTART', new DateTimeImmutable($event->start_date->format('Y-m-d')), ['VALUE' => 'DATE']);
            $vevent->add('DTEND', new DateTimeImmutable($event->end_date->addDay()->format('Y-m-d')), ['VALUE' => 'DATE']);
        } else {
            $vevent->add('DTSTART', new DateTimeImmutable($event->start_date->format('Y-m-d').' '.$event->start_time));
            $vevent->add('DTEND', new DateTimeImmutable($event->end_date->format('Y-m-d').' '.$event->end_time));
        }
        if ($event->repeat !== 'none') $vevent->add('RRULE', $this->rrule($event));
        $calendar->add($vevent);
        return $calendar->serialize();
    }

    private function rrule(CalendarEvent $event): string
    {
        $freq = strtoupper($event->repeat);
        $rule = "FREQ={$freq};INTERVAL={$event->repeat_interval}";
        if ($event->repeat_until) $rule .= ';UNTIL='.$event->repeat_until->format('Ymd').'T235959Z';
        return $rule;
    }

    private function recurrence(string $rrule): array
    {
        $parts = collect(explode(';', $rrule))->mapWithKeys(function ($part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, null); return [$key => $value];
        });
        $repeat = match ($parts->get('FREQ')) { 'DAILY' => 'daily', 'WEEKLY' => 'weekly', 'MONTHLY' => 'monthly', 'YEARLY' => 'yearly', default => 'none' };
        $until = $parts->get('UNTIL');
        return [$repeat, (int) ($parts->get('INTERVAL') ?: 1), $until ? substr($until, 0, 4).'-'.substr($until, 4, 2).'-'.substr($until, 6, 2) : null];
    }

    private function parseMultiStatus(string $xml): array
    {
        $doc = new \DOMDocument();
        if (! @$doc->loadXML($xml)) throw new RuntimeException('Nextcloud returned invalid DAV XML.');
        $xp = new \DOMXPath($doc);
        $xp->registerNamespace('d', 'DAV:'); $xp->registerNamespace('c', 'urn:ietf:params:xml:ns:caldav');
        $out = [];
        foreach ($xp->query('//d:response') as $response) {
            $href = trim($xp->evaluate('string(d:href)', $response));
            $status = $xp->evaluate('string(d:propstat/d:status)', $response);
            $etag = $xp->evaluate('string(d:propstat/d:prop/d:getetag)', $response);
            $data = $xp->evaluate('string(d:propstat/d:prop/c:calendar-data)', $response);
            $out[] = ['href' => $href, 'etag' => $etag ?: null, 'data' => $data ?: null, 'deleted' => str_contains($status, '404'), 'status' => str_contains($status, '404') ? 404 : 200];
        }
        return $out;
    }

    private function extractSyncToken(string $xml): ?string
    {
        $doc = new \DOMDocument(); if (! @$doc->loadXML($xml)) return null;
        $xp = new \DOMXPath($doc); $xp->registerNamespace('d', 'DAV:');
        return ($token = trim($xp->evaluate('string(//d:sync-token)'))) ?: null;
    }

    private function href(?string $href): ?string
    {
        return $href ? $this->client->getAbsoluteUrl($href) : null;
    }
}
