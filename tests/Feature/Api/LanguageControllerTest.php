<?php

use App\Models\Job;
use App\Models\Language;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('can list all languages', function () {
    Language::factory()->count(5)->create();

    $response = $this->getJson('/api/languages');

    $response->assertOk()
        ->assertJsonCount(5)
        ->assertJsonStructure([
            '*' => ['id', 'code', 'name', 'created_at', 'updated_at'],
        ]);
});

test('can create a language', function () {
    $data = [
        'code' => 'fr',
        'name' => 'French',
    ];

    $response = $this->postJson('/api/languages', $data);

    $response->assertCreated()
        ->assertJsonPath('code', 'fr')
        ->assertJsonPath('name', 'French');

    $this->assertDatabaseHas('languages', $data);
});

test('validates required fields when creating language', function () {
    $response = $this->postJson('/api/languages', []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['code', 'name']);
});

test('validates unique code when creating language', function () {
    Language::factory()->create(['code' => 'en']);

    $response = $this->postJson('/api/languages', [
        'code' => 'en',
        'name' => 'English',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);
});

test('can update a language', function () {
    $language = Language::factory()->create(['code' => 'en', 'name' => 'English']);

    $response = $this->putJson("/api/languages/{$language->id}", [
        'name' => 'American English',
    ]);

    $response->assertOk()
        ->assertJsonPath('name', 'American English');

    $this->assertDatabaseHas('languages', [
        'id' => $language->id,
        'name' => 'American English',
    ]);
});

test('validates unique code when updating language', function () {
    $english = Language::factory()->create(['code' => 'en']);
    $french = Language::factory()->create(['code' => 'fr']);

    $response = $this->putJson("/api/languages/{$french->id}", [
        'code' => 'en',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);
});

test('can delete a language not in use', function () {
    $language = Language::factory()->create();

    $response = $this->deleteJson("/api/languages/{$language->id}");

    $response->assertNoContent();
    $this->assertDatabaseMissing('languages', ['id' => $language->id]);
});

test('cannot delete a language used by jobs', function () {
    $sourceLanguage = Language::factory()->create();
    $targetLanguage = Language::factory()->create();

    Job::factory()
        ->for($this->user)
        ->for($sourceLanguage, 'sourceLanguage')
        ->for($targetLanguage, 'targetLanguage')
        ->create();

    $response = $this->deleteJson("/api/languages/{$targetLanguage->id}");

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['language']);

    $this->assertDatabaseHas('languages', ['id' => $targetLanguage->id]);
});
