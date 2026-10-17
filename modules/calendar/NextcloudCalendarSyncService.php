<?php

namespace Modules\Calendar;

use App\Models\Family;
use App\Models\User;
use App\Support\Tenancy\CurrentFamily;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

final class NextcloudCalendarSyncService
{
    public function sync(CalendarIntegration $integration, bool $initial = false): void
    {
        CurrentFamily::set($integration->family_id);

        try {
            $adapter = new NextcloudCalDavAdapter($integration);
            $result = $adapter->pullChanges($integration->sync_cursor);
            foreach ($result['changes'] as $change) {
                $remote = $adapter->parse($change);
                $this->applyRemote($integration, $remote);
            }
            $integration->forceFill([
                'sync_cursor' => $result['cursor'] ?: $integration->sync_cursor,
                'status' => 'active', 'last_synced_at' => now(), 'last_error' => null,
            ])->save();

            if ($initial) {
                CalendarEvent::where('family_id', $integration->family_id)->get()->each(function (CalendarEvent $event) use ($integration) {
                    // Events imported from Outlook (or another provider) stay bound to that source;
                    // only native onsplan events fan out to a newly connected provider.
                    if (! $event->syncs()->exists() && ! $integration->eventSyncs()->where('calendar_event_id', $event->id)->exists()) {
                        $this->pushEvent($event, $integration);
                    }
                });
            }
        } catch (Throwable $e) {
            $status = in_array((int) ($e->getCode()), [401, 403], true)
                || str_contains($e->getMessage(), ' 401') || str_contains($e->getMessage(), ' 403')
                ? 'reauthorization_required' : 'error';
            $integration->forceFill(['status' => $status, 'last_error' => mb_substr($e->getMessage(), 0, 1000)])->save();
            Log::warning('Nextcloud calendar sync failed', ['family_id' => $integration->family_id, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function pushEvent(CalendarEvent $event, CalendarIntegration $integration): void
    {
        $adapter = new NextcloudCalDavAdapter($integration);
        $sync = $integration->eventSyncs()->where('calendar_event_id', $event->id)->first();
        $remote = $sync ? $adapter->update($sync, $event) : $adapter->create($event);
        $sync ??= new CalendarEventSync(['family_id' => $event->family_id, 'calendar_integration_id' => $integration->id]);
        $sync->fill([
            'calendar_event_id' => $event->id,
            'external_event_id' => $remote['uid'] ?? $remote['href'],
            'external_href' => $remote['href'],
            'external_ical_uid' => $remote['uid'] ?? $sync->external_ical_uid,
            'etag' => $remote['etag'] ?? null,
            'external_recurrence' => $event->repeat !== 'none' ? $event->repeat : null,
            'origin' => 'onsplan', 'sync_hash' => hash('sha256', json_encode($event->formValues())),
            'local_changed_at' => $event->updated_at, 'external_changed_at' => now(), 'external_deleted' => false,
        ])->save();
    }

    public function deleteEvent(CalendarEvent $event, CalendarIntegration $integration): void
    {
        $sync = $integration->eventSyncs()->where('calendar_event_id', $event->id)->first();
        if ($sync) (new NextcloudCalDavAdapter($integration))->delete($sync);
    }

    private function applyRemote(CalendarIntegration $integration, array $remote): void
    {
        $sync = $integration->eventSyncs()
            ->where(fn ($query) => $query->where('external_href', $remote['href'] ?? null)->orWhere('external_ical_uid', $remote['uid'] ?? null))
            ->first();
        if (($remote['deleted'] ?? false) === true) {
            $sync?->event?->delete();
            $sync?->delete();
            return;
        }

        if ($sync?->event && $sync->origin === 'onsplan' && $sync->local_changed_at?->greaterThan($sync->external_changed_at)) {
            $this->pushEvent($sync->event, $integration);
            return;
        }

        $data = $remote;
        unset($data['uid'], $data['href'], $data['etag'], $data['raw_recurrence'], $data['deleted']);
        if ($sync?->event) {
            $sync->event->applyUpdate($data);
            $event = $sync->event->fresh();
        } else {
            $owner = User::findOrFail(Family::findOrFail($integration->family_id)->owner_id);
            $event = CalendarEvent::createFor($owner, CalendarEvent::validated($data));
        }
        $sync ??= new CalendarEventSync(['family_id' => $integration->family_id, 'calendar_integration_id' => $integration->id]);
        $sync->fill([
            'calendar_event_id' => $event->id, 'external_event_id' => $remote['uid'] ?? $remote['href'],
            'external_href' => $remote['href'], 'external_ical_uid' => $remote['uid'] ?? null,
            'etag' => $remote['etag'] ?? null, 'external_recurrence' => $remote['raw_recurrence'] ?? null,
            'origin' => 'nextcloud_caldav', 'sync_hash' => hash('sha256', json_encode($data)),
            'local_changed_at' => $event->updated_at, 'external_changed_at' => now(), 'external_deleted' => false,
        ])->save();
    }
}
