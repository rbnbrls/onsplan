<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Calendar\CalendarIntegration;
use Modules\Calendar\CalendarSyncService;
use Modules\Calendar\MicrosoftGraphClient;

class SyncMicrosoftCalendars extends Command
{
    protected $signature = 'calendar:sync-outlook';
    protected $description = 'Reconcile connected Outlook calendars';

    public function handle(CalendarSyncService $sync): int
    {
        CalendarIntegration::where('provider', 'microsoft_graph')->whereIn('status', ['active', 'error'])->each(function (CalendarIntegration $integration) use ($sync) {
            try {
                $sync->sync($integration);
                if ($integration->subscription_id && $integration->subscription_expires_at?->isPast()) {
                    $this->renew($integration);
                }
            } catch (\Throwable $e) {
                $this->warn("Family {$integration->family_id}: {$e->getMessage()}");
            }
        });

        return self::SUCCESS;
    }

    private function renew(CalendarIntegration $integration): void
    {
        if (! config('services.microsoft.webhook_url') || ! $integration->subscription_id) return;
        $response = (new MicrosoftGraphClient($integration))->patch('/subscriptions/'.$integration->subscription_id, [
            'expirationDateTime' => now()->addHours(40)->toIso8601String(),
        ]);
        $integration->update(['subscription_expires_at' => $response['expirationDateTime'] ?? null]);
    }
}
