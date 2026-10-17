<?php

use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Modules\Notifications\PushAlert;

use function Pest\Laravel\actingAs;

it('stores and removes a push subscription', function () {
    $user = User::factory()->create();

    actingAs($user)->post('/push/subscribe', [
        'endpoint' => 'https://push.example.com/abc',
        'keys' => ['p256dh' => 'p256dh-key', 'auth' => 'auth-key'],
    ])->assertNoContent();
    expect($user->pushSubscriptions()->count())->toBe(1);

    actingAs($user)->delete('/push/subscribe', ['endpoint' => 'https://push.example.com/abc'])->assertNoContent();
    expect($user->pushSubscriptions()->count())->toBe(0);
});

it('tags each push uniquely (by notification id) so they never collapse into one', function () {
    $user = User::factory()->create();
    $note = new PushAlert('Shopping', 'added milk', '/m/shopping/lists/1', 'shopping');
    $note->id = 'note-123'; // set when the notification is actually sent
    $msg = $note->toWebPush($user, $note);

    // Tag is the unique notification id, NOT the category, so the SW shows each action on its own.
    expect($msg->toArray()['tag'])->toBe('note-123')
        ->and($msg->toArray()['data']['categoryLabel'])->toBe('Shopping');
});

it('carries no category label for an uncategorised push', function () {
    $user = User::factory()->create();
    $msg = (new PushAlert('onsplan', 'hi'))->toWebPush($user, new PushAlert('x', 'y'));

    expect($msg->toArray()['data']['categoryLabel'] ?? null)->toBeNull();
});

it('sends a test push to the current user', function () {
    Notification::fake();
    $user = User::factory()->create();

    actingAs($user)->post('/push/test')->assertNoContent();

    Notification::assertSentTo($user, PushAlert::class);
});
