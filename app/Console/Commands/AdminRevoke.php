<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

// Takes effect on the admin's next request: every /api/admin call re-reads the flag.
class AdminRevoke extends Command
{
    protected $signature = 'admin:revoke {email}';

    protected $description = 'Remove platform admin from a onsplan user';

    public function handle(): int
    {
        return AdminGrant::setFlag($this, (string) $this->argument('email'), false);
    }
}
