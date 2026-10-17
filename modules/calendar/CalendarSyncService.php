<?php

namespace Modules\Calendar;

use App\Models\Family;
use App\Models\User;
use App\Support\Tenancy\CurrentFamily;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

final class CalendarSyncService
{
    public function sync(CalendarIntegration $integration, bool $initial = false): void
    {
        if ($integration->provider === 'nextcloud_caldav') {
            app(NextcloudCalendarSyncService::class)->sync($integration, $initial);
            return;
        }
        CurrentFamily::set($integration->family_id);

        try {
            $graph = new MicrosoftGraphClient($integration);
            $page = $integration->sync_cursor
                ? $graph->raw($integration->sync_cursor)
                : $graph->get("/me/calendars/{$integration->calendar_id}/events/delta", [
                    '$select' => 'id,iCalUId,subject,body,start,end,isAllDay,recurrence,lastModifiedDateTime',
                ]);

            $lastPage = $page;
            while ($page) {
                foreach ($page['value'] ?? [] as $remote) {
                    $this->applyRemote($integration, $remote);
                }
                $lastPage = $page;
                $page = isset($page['@odata.nextLink']) ? $graph->raw($page['@odata.nextLink']) : null;
            }
            if (isset($lastPage['@odata.deltaLink'])) $integration->sync_cursor = $lastPage['@odata.deltaLink'];

            if ($initial) {
                CalendarEvent::where('family_id', $integration->family_id)->get()->each(function (CalendarEvent $event) use ($integration) {
                    if (! $integration->eventSyncs()->where('calendar_event_id', $event->id)->exists()) {
                        $this->pushEvent($event, $integration);
                    }
                });
            }

            $integration->forceFill(['status' => 'active', 'last_synced_at' => now(), 'last_error' => null])->save();
        } catch (Throwable $e) {
            $integration->forceFill([
                'status' => str_contains(strtolower($e->getMessage()), 'authorization') ? 'reauthorization_required' : 'error',
                'last_error' => mb_substr($e->getMessage(), 0, 1000),
            ])->save();
            Log::warning('Microsoft calendar sync failed', ['family_id' => $integration->family_id, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function pushEvent(CalendarEvent $event, ?CalendarIntegration $only = null): void
    {
        $source = $event->syncs()->where('origin', '!=', 'onsplan')->first()?->integration;
        $integrations = $only ? collect([$only]) : ($source ? collect([$source]) : CalendarIntegration::where('family_id', $event->family_id)->where('status', 'active')->get());
        foreach ($integrations as $integration) {
            if ($integration->provider === 'nextcloud_caldav') {
                app(NextcloudCalendarSyncService::class)->pushEvent($event, $integration);
                continue;
            }

            $sync = $integration->eventSyncs()->where('calendar_event_id', $event->id)->first();
            $graph = new MicrosoftGraphClient($integration);
            $payload = $this->toGraph($event);
            $remote = $sync
                ? $graph->patch("/me/calendars/{$integration->calendar_id}/events/{$sync->external_event_id}", $payload)
                : $graph->post("/me/calendars/{$integration->calendar_id}/events", $payload);
            $sync ??= new CalendarEventSync(['family_id' => $event->family_id, 'calendar_integration_id' => $integration->id]);
            $sync->fill([
                'calendar_event_id' => $event->id, 'external_event_id' => $remote['id'],
                'external_ical_uid' => $remote['iCalUId'] ?? null, 'origin' => 'onsplan',
                'sync_hash' => $this->hash($payload), 'local_changed_at' => $event->updated_at,
                'external_changed_at' => isset($remote['lastModifiedDateTime']) ? CarbonImmutable::parse($remote['lastModifiedDateTime']) : now(),
                'external_deleted' => false,
            ])->save();
        }
    }

    public function deleteEvent(CalendarEvent $event): void
    {
        $source = $event->syncs()->where('origin', '!=', 'onsplan')->first()?->integration;
        $syncs = $event->syncs()->with('integration')->get()->filter(fn ($sync) => ! $source || $sync->integration_id === $source->id);
        foreach ($syncs as $sync) {
            try {
                if ($sync->integration->provider === 'nextcloud_caldav') app(NextcloudCalendarSyncService::class)->deleteEvent($event, $sync->integration);
                else (new MicrosoftGraphClient($sync->integration))->delete("/me/calendars/{$sync->integration->calendar_id}/events/{$sync->external_event_id}");
            } finally {
                $sync->delete();
            }
        }
    }

    private function applyRemote(CalendarIntegration $integration, array $remote): void
    {
        $sync = $integration->eventSyncs()->where('external_event_id', $remote['id'] ?? '')->first();
        if (isset($remote['@removed'])) {
            $sync?->event?->delete();
            $sync?->delete();
            return;
        }
        $changed = isset($remote['lastModifiedDateTime']) ? CarbonImmutable::parse($remote['lastModifiedDateTime']) : now();
        if ($sync?->event && $sync->local_changed_at?->greaterThan($changed)) {
            $this->pushEvent($sync->event);
            return;
        }

        $data = $this->fromGraph($remote);
        if ($sync?->event) {
            $sync->event->applyUpdate($data);
            $event = $sync->event->fresh();
        } else {
            $owner = User::findOrFail(Family::findOrFail($integration->family_id)->owner_id);
            $event = CalendarEvent::createFor($owner, CalendarEvent::validated($data));
        }
        $sync ??= new CalendarEventSync(['family_id' => $integration->family_id, 'calendar_integration_id' => $integration->id]);
        $sync->fill([
            'calendar_event_id' => $event->id, 'external_event_id' => $remote['id'],
            'external_ical_uid' => $remote['iCalUId'] ?? null, 'origin' => 'outlook',
            'sync_hash' => $this->hash($data), 'local_changed_at' => $event->updated_at,
            'external_changed_at' => $changed, 'external_deleted' => false,
        ])->save();
    }

    private function toGraph(CalendarEvent $event): array
    {
        $allDay = ! $event->start_time;
        $payload = [
            'subject' => $event->title,
            'body' => ['contentType' => 'text', 'content' => (string) ($event->notes ?? '')],
            'isAllDay' => $allDay,
            'start' => ['dateTime' => $event->start_date->toDateString().'T'.($event->start_time ?: '00:00').':00', 'timeZone' => config('app.timezone', 'UTC')],
            'end' => ['dateTime' => ($allDay ? $event->end_date->addDay() : $event->end_date)->toDateString().'T'.($event->end_time ?: '00:00').':00', 'timeZone' => config('app.timezone', 'UTC')],
        ];
        $payload['recurrence'] = $event->repeat !== 'none' ? $this->recurrenceToGraph($event) : null;
        return $payload;
    }

    private function fromGraph(array $remote): array
    {
        $start = CarbonImmutable::parse($remote['start']['dateTime'] ?? $remote['start']['date']);
        $end = CarbonImmutable::parse($remote['end']['dateTime'] ?? $remote['end']['date']);
        $allDay = (bool) ($remote['isAllDay'] ?? false);
        if ($allDay) $end = $end->subDay();
        $recurrence = $this->recurrenceFromGraph($remote['recurrence'] ?? null);
        return [
            'title' => $remote['subject'] ?? '(Untitled)', 'notes' => strip_tags($remote['body']['content'] ?? ''),
            'start_date' => $start->toDateString(), 'end_date' => $end->toDateString(),
            'start_time' => $allDay ? null : $start->format('H:i'), 'end_time' => $allDay ? null : $end->format('H:i'),
            ...$recurrence,
        ];
    }

    private function recurrenceToGraph(CalendarEvent $event): array
    {
        $type = match ($event->repeat) { 'daily' => 'daily', 'weekly' => 'weekly', 'monthly' => 'absoluteMonthly', 'yearly' => 'absoluteYearly', default => 'daily' };
        $pattern = ['type' => $type, 'interval' => $event->repeat_interval];
        if (in_array($type, ['absoluteMonthly', 'absoluteYearly'], true)) $pattern['dayOfMonth'] = $event->start_date->day;
        if ($type === 'absoluteYearly') $pattern['month'] = $event->start_date->month;
        return ['pattern' => $pattern, 'range' => $event->repeat_until
            ? ['type' => 'endDate', 'startDate' => $event->start_date->toDateString(), 'endDate' => $event->repeat_until->toDateString()]
            : ['type' => 'noEnd', 'startDate' => $event->start_date->toDateString()]];
    }

    private function recurrenceFromGraph(?array $recurrence): array
    {
        $pattern = $recurrence['pattern'] ?? [];
        $repeat = match ($pattern['type'] ?? null) { 'daily' => 'daily', 'weekly' => 'weekly', 'absoluteMonthly' => 'monthly', 'absoluteYearly' => 'yearly', default => 'none' };
        $range = $recurrence['range'] ?? [];
        return ['repeat' => $repeat, 'repeat_interval' => (int) ($pattern['interval'] ?? 1), 'repeat_until' => ($range['type'] ?? null) === 'endDate' ? ($range['endDate'] ?? null) : null];
    }

    private function hash(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }
}
