<?php

use App\Models\User;
use Illuminate\Support\Str;

test('guests cannot view notifications inbox', function () {
    $this->get(route('notifications.index'))->assertRedirect(route('login'));
});

test('authenticated user can view notifications inbox', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('notifications.index'))
        ->assertOk();
});

test('notifications index filters unread', function () {
    $user = User::factory()->create();

    $readId = (string) Str::uuid();
    $user->notifications()->create([
        'id' => $readId,
        'type' => 'App\\Notifications\\Example',
        'data' => ['title' => 'Read note', 'body' => 'x'],
        'read_at' => now(),
    ]);

    $unreadId = (string) Str::uuid();
    $user->notifications()->create([
        'id' => $unreadId,
        'type' => 'App\\Notifications\\Example',
        'data' => ['title' => 'Unread note', 'body' => 'y'],
        'read_at' => null,
    ]);

    $this->actingAs($user)
        ->get(route('notifications.index', ['filter' => 'unread']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('notifications')
            ->where('filter', 'unread')
            ->has('notifications', 1)
            ->where('notifications.0.id', $unreadId));
});

test('user can mark a notification as read', function () {
    $user = User::factory()->create();
    $id = (string) Str::uuid();
    $user->notifications()->create([
        'id' => $id,
        'type' => 'App\\Notifications\\Example',
        'data' => ['title' => 'Tap read'],
        'read_at' => null,
    ]);

    $this->actingAs($user)
        ->post(route('notifications.read', ['notification' => $id]))
        ->assertRedirect();

    expect($user->notifications()->whereKey($id)->value('read_at'))->not->toBeNull();
});

test('user cannot mark another users notification as read', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $id = (string) Str::uuid();
    $owner->notifications()->create([
        'id' => $id,
        'type' => 'App\\Notifications\\Example',
        'data' => ['title' => 'Private'],
        'read_at' => null,
    ]);

    $this->actingAs($other)
        ->post(route('notifications.read', ['notification' => $id]))
        ->assertForbidden();
});

test('mark all read clears unread notifications', function () {
    $user = User::factory()->create();
    $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'App\\Notifications\\Example',
        'data' => ['title' => 'A'],
        'read_at' => null,
    ]);
    $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'App\\Notifications\\Example',
        'data' => ['title' => 'B'],
        'read_at' => null,
    ]);

    $this->actingAs($user)
        ->post(route('notifications.read-all'))
        ->assertRedirect();

    expect($user->unreadNotifications()->count())->toBe(0);
});
