<?php

namespace Modules\Calendar;

interface CalendarProviderAdapter
{
    /** @return list<array{id: string, name: string, writable: bool}> */
    public function discoverCalendars(): array;

    /** @return array{changes: list<array>, cursor: ?string} */
    public function pullChanges(?string $cursor): array;

    /** @return array<string, mixed> */
    public function create(CalendarEvent $event): array;

    /** @return array<string, mixed> */
    public function update(CalendarEventSync $sync, CalendarEvent $event): array;

    public function delete(CalendarEventSync $sync): void;
}
