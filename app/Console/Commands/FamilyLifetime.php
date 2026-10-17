<?php

namespace App\Console\Commands;

use App\Models\Family;
use App\Support\Billing\Billing;
use Illuminate\Console\Command;

// Grant / revoke a family's comped lifetime access from the console. Same write path as the
// admin.onsplan.nl toggle (Billing::setLifetime), so both log and behave identically.
class FamilyLifetime extends Command
{
    protected $signature = 'family:lifetime {family : The family id} {--revoke : Take lifetime access away}';

    protected $description = 'Grant (or --revoke) a family lifetime onsplan access (comped / VIP)';

    public function handle(): int
    {
        $family = Family::find((int) $this->argument('family'));
        if (! $family) {
            $this->error("No family #{$this->argument('family')}.");

            return self::FAILURE;
        }
        $on = ! $this->option('revoke');
        Billing::setLifetime($family, $on, 'console');
        $this->info(($on ? 'Granted' : 'Revoked')." lifetime access: {$family->name} (#{$family->id})");

        return self::SUCCESS;
    }
}
