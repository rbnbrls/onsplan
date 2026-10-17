<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

// Who may sign in to admin.onsplan.nl. Run from the production console; the only way to set the flag.
class AdminGrant extends Command
{
    protected $signature = 'admin:grant {email}';

    protected $description = 'Make a onsplan user a platform admin (admin.onsplan.nl)';

    public function handle(): int
    {
        return self::setFlag($this, (string) $this->argument('email'), true);
    }

    public static function setFlag(Command $command, string $email, bool $on): int
    {
        $user = User::query()->whereRaw('lower(email) = ?', [mb_strtolower(trim($email))])->first();
        if (! $user) {
            $command->error("No user with email {$email}.");

            return self::FAILURE;
        }
        $user->forceFill(['is_platform_admin' => $on])->save();
        $command->info(($on ? 'Granted' : 'Revoked')." platform admin: {$user->email} (#{$user->id})");

        return self::SUCCESS;
    }
}
