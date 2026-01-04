<?php

use App\Models\Setting;
use App\Models\User;

test('can get settings with default values when no settings exist', function () {
    User::factory()->create();

    $response = $this->getJson('/api/app-settings');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                'max_retries',
                'retry_delay',
                'score_threshold',
                'manual_check_threshold',
            ],
        ]);

    $data = $response->json('data');
    expect($data['max_retries'])->toBe(3);
    expect($data['retry_delay'])->toBe(5000);
    expect($data['score_threshold'])->toBe(70);
    expect($data['manual_check_threshold'])->toBe(60);
});

test('can get existing settings', function () {
    $user = User::factory()->create();
    Setting::factory()->for($user)->create([
        'max_retries' => 5,
        'retry_delay' => 10000,
        'score_threshold' => 80,
        'manual_check_threshold' => 50,
    ]);

    $response = $this->getJson('/api/app-settings');

    $response->assertOk();

    $data = $response->json('data');
    expect($data['max_retries'])->toBe(5);
    expect($data['retry_delay'])->toBe(10000);
    expect($data['score_threshold'])->toBe(80);
    expect($data['manual_check_threshold'])->toBe(50);
});

test('can update settings', function () {
    $user = User::factory()->create();

    $response = $this->putJson('/api/app-settings', [
        'max_retries' => 7,
        'retry_delay' => 15000,
        'score_threshold' => 85,
        'manual_check_threshold' => 55,
    ]);

    $response->assertOk();

    $data = $response->json('data');
    expect($data['max_retries'])->toBe(7);
    expect($data['retry_delay'])->toBe(15000);
    expect($data['score_threshold'])->toBe(85);
    expect($data['manual_check_threshold'])->toBe(55);

    $this->assertDatabaseHas('settings', [
        'user_id' => $user->id,
        'max_retries' => 7,
    ]);
});

test('can partially update settings', function () {
    $user = User::factory()->create();
    Setting::factory()->for($user)->create([
        'max_retries' => 3,
        'retry_delay' => 5000,
    ]);

    $response = $this->putJson('/api/app-settings', [
        'max_retries' => 10,
    ]);

    $response->assertOk();

    $data = $response->json('data');
    expect($data['max_retries'])->toBe(10);
    expect($data['retry_delay'])->toBe(5000); // Unchanged
});

test('validates max_retries range', function () {
    User::factory()->create();

    $response = $this->putJson('/api/app-settings', [
        'max_retries' => 15, // Max is 10
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['max_retries']);
});

test('validates retry_delay range', function () {
    User::factory()->create();

    $response = $this->putJson('/api/app-settings', [
        'retry_delay' => 500, // Min is 1000
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['retry_delay']);
});

test('validates score_threshold range', function () {
    User::factory()->create();

    $response = $this->putJson('/api/app-settings', [
        'score_threshold' => 150, // Max is 100
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['score_threshold']);
});

test('returns 404 when updating without any user', function () {
    // No user exists
    $response = $this->putJson('/api/app-settings', [
        'max_retries' => 5,
    ]);

    $response->assertNotFound();
});
