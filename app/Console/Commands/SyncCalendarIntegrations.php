<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Calendar\CalendarIntegration;
use Modules\Calendar\CalendarSyncService;

final class SyncCalendarIntegrations extends Command
{
    protected $signature = 'calendar:sync-integrations';
    protected $description = 'Reconcile all connected calendar providers';

    public function handle(CalendarSyncService $sync): int
    {
        CalendarIntegration::whereIn('status', ['active', 'error'])->each(function (CalendarIntegration $integration) use ($sync) {
            try {
                $sync->sync($integration);
            } catch (\Throwable $e) {
                $this->warn("Family {$integration->family_id} ({$integration->provider}): {$e->getMessage()}");
            }
        });
        return self::SUCCESS;
    }
}
