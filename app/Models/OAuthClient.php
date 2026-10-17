<?php

namespace App\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;
use Laravel\Passport\Client;

// Passport's client plus one rule: a client flagged `first_party` on its own row is a board client. It
// skips the consent screen when it asks for exactly `board:identity`, and it can NEVER reach the MCP
// permissions screen (AuthorizeController / ApproveController refuse it). The flag lives on the client
// (not in an env list) because an env list drifted from production once: the board client was then
// treated like an MCP client and was handed module read/write scopes. A client also flagged platform_admin
// is admin.onsplan.nl: it asks for exactly board:identity + admin. Registered with
// Passport::useClientModel() in AppServiceProvider.
class OAuthClient extends Client
{
    public const ADMIN_SCOPE = 'board:identity admin';

    protected function casts(): array
    {
        return [...parent::casts(), 'first_party' => 'bool', 'platform_admin' => 'bool'];
    }

    public function skipsAuthorization(Authenticatable $user, array $scopes): bool
    {
        $ids = array_map(fn ($scope) => $scope->id, $scopes);

        return $this->first_party && ($ids === ['board:identity'] || ($this->platform_admin && $ids === ['board:identity', 'admin']));
    }

    // $clientId comes straight from the query string / token row, so it may be an array or null.
    public static function isBoardClient(mixed $clientId): bool
    {
        // Non-UUIDs can't match (and Postgres would 500 on a malformed uuid).
        if (! is_string($clientId) || ! Str::isUuid($clientId)) {
            return false;
        }

        // One primary-key lookup.
        return static::query()->whereKey($clientId)->where('first_party', true)->where('revoked', false)->exists();
    }

    // Same shape as isBoardClient: one primary-key lookup, non-UUIDs never match.
    public static function isPlatformAdminClient(mixed $clientId): bool
    {
        if (! is_string($clientId) || ! Str::isUuid($clientId)) {
            return false;
        }

        return static::query()->whereKey($clientId)->where('first_party', true)->where('platform_admin', true)->where('revoked', false)->exists();
    }
}
