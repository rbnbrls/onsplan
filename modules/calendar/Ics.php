<?php

namespace Modules\Calendar;

use App\Support\Translations;
use Modules\Birthdays\BirthdayDate;

/**
 * RFC 5545 output, hand-written (no dependency for ~60 lines). Events carry their repeat rule (RRULE) so the
 * subscriber expands it; RFC skip semantics for the 31st / Feb 29 match Occurrences. Times are FLOATING
 * (no TZID, no Z): onsplan stores wall-clock times, and floating times show as typed in any client.
 */
final class Ics
{
    private const FREQ = ['daily' => 'DAILY', 'weekly' => 'WEEKLY', 'monthly' => 'MONTHLY', 'yearly' => 'YEARLY'];

    /**
     * @param  iterable<CalendarEvent>  $events
     * @param  iterable<BirthdayDate>  $birthdays
     * @param  ?string  $locale  the feed owner's locale (the route has no session, so SetLocale never runs —
     *                           pass it explicitly so "SUMMARY:Ana's birthday" comes out in their language)
     */
    public static function calendar(string $name, iterable $events, iterable $birthdays, ?string $locale = null): string
    {
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//onsplan//Calendar//EN', 'CALSCALE:GREGORIAN', 'X-WR-CALNAME:'.self::text($name)];
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'onsplan.nl';
        foreach ($events as $e) {
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = "UID:event-{$e->id}@{$host}";
            $lines[] = 'DTSTAMP:'.$e->updated_at->utc()->format('Ymd\THis\Z');
            $lines[] = 'SUMMARY:'.self::text($e->title);
            if ($e->notes) {
                $lines[] = 'DESCRIPTION:'.self::text($e->notes);
            }
            if ($e->start_time === null) {
                $lines[] = 'DTSTART;VALUE=DATE:'.$e->start_date->format('Ymd');
                $lines[] = 'DTEND;VALUE=DATE:'.$e->end_date->copy()->addDay()->format('Ymd'); // exclusive
            } else {
                $lines[] = 'DTSTART:'.$e->start_date->format('Ymd').'T'.str_replace(':', '', $e->start_time).'00';
                $lines[] = 'DTEND:'.$e->end_date->format('Ymd').'T'.str_replace(':', '', $e->end_time).'00';
            }
            if ($e->repeat !== 'none') {
                $rule = 'RRULE:FREQ='.self::FREQ[$e->repeat].($e->repeat_interval > 1 ? ";INTERVAL={$e->repeat_interval}" : '');
                if ($e->repeat_until) {
                    // UNTIL must match DTSTART's type: DATE for all-day, local date-time for timed.
                    $rule .= ';UNTIL='.$e->repeat_until->format('Ymd').($e->start_time === null ? '' : 'T235959');
                }
                $lines[] = $rule;
            }
            $lines[] = 'END:VEVENT';
        }
        foreach ($birthdays as $b) {
            if (! $b->person) {
                continue;
            }
            // Anchor year: the birth year when known, else 2000 (a leap year, so a Feb 29 birthday is valid).
            $year = $b->year() ?? 2000;
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = "UID:birthday-{$b->id}@{$host}";
            $lines[] = 'DTSTAMP:'.now()->utc()->format('Ymd\THis\Z');
            $lines[] = 'SUMMARY:'.self::text(strtr(Translations::get('calendar.birthday', $locale), [':name' => $b->person->name]));
            $lines[] = sprintf('DTSTART;VALUE=DATE:%04d%02d%02d', $year, $b->month, $b->day);
            $lines[] = 'RRULE:FREQ=YEARLY';
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map([self::class, 'fold'], $lines))."\r\n";
    }

    private static function text(string $s): string
    {
        // Any line break (CRLF, lone CR from an MCP client, LF) becomes an escaped \n; other control chars
        // (except tab, which TEXT allows) are dropped, since a raw CR or NUL would break the content line.
        $s = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', str_replace(["\r\n", "\r"], "\n", $s));

        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $s);
    }

    // Fold at 75 octets without splitting a UTF-8 character (mb_strcut cuts on byte counts at char boundaries).
    private static function fold(string $line): string
    {
        $out = [];
        $first = true;
        while (strlen($line) > ($first ? 75 : 74)) {
            $chunk = mb_strcut($line, 0, $first ? 75 : 74, 'UTF-8');
            $out[] = $first ? $chunk : ' '.$chunk;
            $line = substr($line, strlen($chunk));
            $first = false;
        }
        $out[] = $first ? $line : ' '.$line;

        return implode("\r\n", $out);
    }
}
