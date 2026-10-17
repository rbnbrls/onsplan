<?php

use App\Models\OAuthClient;
use App\Models\User;

// Platform admins are a flag on the user row, set only from the console (never from a request).

it('grants and revokes platform admin by email', function () {
    $user = User::factory()->create(['email' => 'boss@example.com']);

    $this->artisan('admin:grant', ['email' => 'BOSS@example.com'])->assertSuccessful();
    expect($user->fresh()->is_platform_admin)->toBeTrue();

    $this->artisan('admin:revoke', ['email' => 'boss@example.com'])->assertSuccessful();
    expect($user->fresh()->is_platform_admin)->toBeFalse();
});

it('fails for an unknown email', function () {
    $this->artisan('admin:grant', ['email' => 'nobody@example.com'])->assertFailed();
});

it('cannot be mass-assigned', function () {
    $user = User::factory()->create();
    $user->update(['is_platform_admin' => true]);

    expect($user->fresh()->is_platform_admin)->toBeFalse();
});

it('registers an admin client with --admin', function () {
    mcpKeys();
    $this->artisan('board:client', ['name' => 'onsplan admin', 'redirect' => 'https://admin.onsplan.nl/auth/callback', '--admin' => true])->assertSuccessful();

    $client = OAuthClient::query()->where('name', 'onsplan admin')->firstOrFail();
    expect($client->first_party)->toBeTrue()
        ->and($client->platform_admin)->toBeTrue()
        ->and(OAuthClient::isPlatformAdminClient($client->getKey()))->toBeTrue()
        ->and(OAuthClient::isPlatformAdminClient('not-a-uuid'))->toBeFalse()
        ->and(OAuthClient::isPlatformAdminClient(boardClient()->getKey()))->toBeFalse();
});
