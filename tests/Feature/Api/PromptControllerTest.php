<?php

use App\Enums\JobItemStatus;
use App\Models\Job;
use App\Models\JobItem;
use App\Models\Language;
use App\Models\Prompt;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->sourceLanguage = Language::factory()->create();
    $this->targetLanguage = Language::factory()->create();
});

test('can list all prompts', function () {
    Prompt::factory()->count(5)->create();

    $response = $this->getJson('/api/prompts');

    $response->assertOk()
        ->assertJsonCount(5)
        ->assertJsonStructure([
            '*' => ['id', 'name', 'content', 'is_active', 'created_at', 'updated_at'],
        ]);
});

test('can create a prompt', function () {
    $data = [
        'name' => 'Test Prompt',
        'content' => 'Translate the following text to {target_language}:',
    ];

    $response = $this->postJson('/api/prompts', $data);

    $response->assertCreated()
        ->assertJsonPath('name', 'Test Prompt')
        ->assertJsonPath('content', 'Translate the following text to {target_language}:');

    $this->assertDatabaseHas('prompts', $data);
});

test('validates required fields when creating prompt', function () {
    $response = $this->postJson('/api/prompts', []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'content']);
});

test('can show a single prompt', function () {
    $prompt = Prompt::factory()->create();

    $response = $this->getJson("/api/prompts/{$prompt->id}");

    $response->assertOk()
        ->assertJsonPath('id', $prompt->id)
        ->assertJsonPath('name', $prompt->name)
        ->assertJsonStructure([
            'id', 'name', 'content', 'is_active', 'created_at', 'updated_at',
        ]);
});

test('can update a prompt', function () {
    $prompt = Prompt::factory()->create();

    $response = $this->putJson("/api/prompts/{$prompt->id}", [
        'name' => 'Updated Name',
        'content' => 'Updated content',
    ]);

    $response->assertOk()
        ->assertJsonPath('name', 'Updated Name')
        ->assertJsonPath('content', 'Updated content');

    $this->assertDatabaseHas('prompts', [
        'id' => $prompt->id,
        'name' => 'Updated Name',
    ]);
});

test('can delete a prompt not in use', function () {
    $prompt = Prompt::factory()->create();

    $response = $this->deleteJson("/api/prompts/{$prompt->id}");

    $response->assertNoContent();
    $this->assertDatabaseMissing('prompts', ['id' => $prompt->id]);
});

test('cannot delete a prompt being used by active jobs', function () {
    $prompt = Prompt::factory()->create();

    // Create a job with active items using this prompt
    $job = Job::factory()
        ->for($this->user)
        ->for($this->sourceLanguage, 'sourceLanguage')
        ->for($this->targetLanguage, 'targetLanguage')
        ->for($prompt)
        ->has(JobItem::factory()->state(['status' => JobItemStatus::Processing]), 'items')
        ->create(['total_items' => 1]);

    $response = $this->deleteJson("/api/prompts/{$prompt->id}");

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['prompt']);

    $this->assertDatabaseHas('prompts', ['id' => $prompt->id]);
});

test('prompt is_active is true when used by running job', function () {
    $prompt = Prompt::factory()->create();

    Job::factory()
        ->for($this->user)
        ->for($this->sourceLanguage, 'sourceLanguage')
        ->for($this->targetLanguage, 'targetLanguage')
        ->for($prompt)
        ->has(JobItem::factory()->state(['status' => JobItemStatus::Queued]), 'items')
        ->create(['total_items' => 1]);

    $response = $this->getJson("/api/prompts/{$prompt->id}");

    $response->assertOk()
        ->assertJsonPath('is_active', true);
});

test('prompt is_active is false when not used by running jobs', function () {
    $prompt = Prompt::factory()->create();

    // Create a job older than 7 days
    Job::factory()
        ->for($this->user)
        ->for($this->sourceLanguage, 'sourceLanguage')
        ->for($this->targetLanguage, 'targetLanguage')
        ->for($prompt)
        ->has(JobItem::factory()->state(['status' => JobItemStatus::Done]), 'items')
        ->create(['total_items' => 1, 'created_at' => now()->subDays(8)]);

    $response = $this->getJson("/api/prompts/{$prompt->id}");

    $response->assertOk()
        ->assertJsonPath('is_active', false);
});
