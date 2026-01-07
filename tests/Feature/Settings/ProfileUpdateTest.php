<?php

use App\Models\User;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->getJson('/api/settings/profile');

    $response->assertOk()
        ->assertJsonPath('user.id', $user->id);
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patchJson('/api/settings/profile', [
            'name' => 'Updated Name',
        ]);

    $response->assertOk()
        ->assertJsonPath('message', 'Profile updated successfully');

    $user->refresh();

    expect($user->name)->toBe('Updated Name');
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->deleteJson('/api/settings/profile');

    $response->assertOk()
        ->assertJsonPath('message', 'Account deleted successfully');

    expect($user->fresh())->toBeNull();
});
