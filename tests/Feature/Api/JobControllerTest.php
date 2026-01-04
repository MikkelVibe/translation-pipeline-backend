<?php

use App\Enums\JobItemStatus;
use App\Enums\JobStatus;
use App\Models\Job;
use App\Models\JobItem;
use App\Models\Language;
use App\Models\Prompt;
use App\Models\User;
use App\Services\DataProvider\ProductDataProviderInterface;
use App\Services\RabbitMQService;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->sourceLanguage = Language::factory()->create(['code' => 'en', 'name' => 'English']);
    $this->targetLanguage = Language::factory()->create(['code' => 'da', 'name' => 'Danish']);
    $this->prompt = Prompt::factory()->create();
});

test('can list jobs', function () {
    Job::factory()
        ->count(5)
        ->for($this->user)
        ->for($this->sourceLanguage, 'sourceLanguage')
        ->for($this->targetLanguage, 'targetLanguage')
        ->for($this->prompt)
        ->create();

    $response = $this->getJson('/api/jobs');

    $response->assertOk()
        ->assertJsonCount(5, 'data')
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'total_items',
                    'status',
                    'completed_items',
                    'failed_items',
                    'progress_percentage',
                    'created_at',
                    'source_language',
                    'target_language',
                    'prompt',
                ],
            ],
            'links',
            'total',
            'per_page',
        ]);
});

test('can filter jobs by status', function () {
    // Create a job with completed items
    $completedJob = Job::factory()
        ->for($this->user)
        ->for($this->sourceLanguage, 'sourceLanguage')
        ->for($this->targetLanguage, 'targetLanguage')
        ->for($this->prompt)
        ->has(JobItem::factory()->count(3)->state(['status' => JobItemStatus::Done]), 'items')
        ->create(['total_items' => 3]);

    // Create a job with failed items
    $failedJob = Job::factory()
        ->for($this->user)
        ->for($this->sourceLanguage, 'sourceLanguage')
        ->for($this->targetLanguage, 'targetLanguage')
        ->for($this->prompt)
        ->has(JobItem::factory()->count(2)->state(['status' => JobItemStatus::Error]), 'items')
        ->create(['total_items' => 2]);

    $response = $this->getJson('/api/jobs?status=completed');

    $response->assertOk()
        ->assertJsonCount(1, 'data');

    expect($response->json('data.0.id'))->toBe($completedJob->id);
});

test('can filter jobs by language', function () {
    $germanLanguage = Language::factory()->create(['code' => 'de', 'name' => 'German']);

    $danishJob = Job::factory()
        ->for($this->user)
        ->for($this->sourceLanguage, 'sourceLanguage')
        ->for($this->targetLanguage, 'targetLanguage')
        ->for($this->prompt)
        ->create();

    $germanJob = Job::factory()
        ->for($this->user)
        ->for($this->sourceLanguage, 'sourceLanguage')
        ->for($germanLanguage, 'targetLanguage')
        ->for($this->prompt)
        ->create();

    $response = $this->getJson('/api/jobs?language=da');

    $response->assertOk()
        ->assertJsonCount(1, 'data');

    expect($response->json('data.0.id'))->toBe($danishJob->id);
});

test('can show a single job with relations', function () {
    $job = Job::factory()
        ->for($this->user)
        ->for($this->sourceLanguage, 'sourceLanguage')
        ->for($this->targetLanguage, 'targetLanguage')
        ->for($this->prompt)
        ->create();

    $response = $this->getJson("/api/jobs/{$job->id}");

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                'id',
                'total_items',
                'status',
                'source_language' => ['id', 'code', 'name'],
                'target_language' => ['id', 'code', 'name'],
                'prompt' => ['id', 'name', 'content'],
            ],
        ]);
});

test('can get paginated job items', function () {
    $job = Job::factory()
        ->for($this->user)
        ->for($this->sourceLanguage, 'sourceLanguage')
        ->for($this->targetLanguage, 'targetLanguage')
        ->for($this->prompt)
        ->has(JobItem::factory()->count(25), 'items')
        ->create(['total_items' => 25]);

    $response = $this->getJson("/api/jobs/{$job->id}/items?per_page=10");

    $response->assertOk()
        ->assertJsonCount(10, 'data')
        ->assertJsonPath('total', 25);
});

test('can get job errors only', function () {
    $job = Job::factory()
        ->for($this->user)
        ->for($this->sourceLanguage, 'sourceLanguage')
        ->for($this->targetLanguage, 'targetLanguage')
        ->for($this->prompt)
        ->has(JobItem::factory()->count(5)->state(['status' => JobItemStatus::Done]), 'items')
        ->has(JobItem::factory()->count(2)->state([
            'status' => JobItemStatus::Error,
            'error_message' => 'Translation failed',
        ]), 'items')
        ->create(['total_items' => 7]);

    $response = $this->getJson("/api/jobs/{$job->id}/errors");

    $response->assertOk()
        ->assertJsonCount(2, 'data');

    foreach ($response->json('data') as $item) {
        expect($item['status'])->toBe('error');
    }
});

test('returns 404 for non-existent job', function () {
    $response = $this->getJson('/api/jobs/99999');

    $response->assertNotFound();
});

test('can paginate jobs with custom per_page', function () {
    Job::factory()
        ->count(15)
        ->for($this->user)
        ->for($this->sourceLanguage, 'sourceLanguage')
        ->for($this->targetLanguage, 'targetLanguage')
        ->for($this->prompt)
        ->create();

    $response = $this->getJson('/api/jobs?per_page=5');

    $response->assertOk()
        ->assertJsonCount(5, 'data')
        ->assertJsonPath('per_page', 5)
        ->assertJsonPath('total', 15);
});

test('can create a job with product ids', function () {
    // Mock RabbitMQ service
    $mockRabbit = Mockery::mock(RabbitMQService::class);
    $mockRabbit->shouldReceive('publish')
        ->once()
        ->withArgs(function ($queue, $payload) {
            return $queue === 'product_fetch_queue'
                && $payload['type'] === 'ids'
                && $payload['ids'] === ['product-1', 'product-2', 'product-3']
                && isset($payload['job_id']);
        });
    $this->app->instance(RabbitMQService::class, $mockRabbit);

    $response = $this->postJson('/api/jobs', [
        'source_lang_id' => $this->sourceLanguage->id,
        'target_lang_id' => $this->targetLanguage->id,
        'prompt_id' => $this->prompt->id,
        'product_ids' => ['product-1', 'product-2', 'product-3'],
    ]);

    $response->assertCreated()
        ->assertJsonStructure([
            'data' => [
                'id',
                'total_items',
                'status',
                'source_language',
                'target_language',
                'prompt',
            ],
            'message',
        ]);

    expect($response->json('data.status'))->toBe(JobStatus::Pending->value);
    expect($response->json('message'))->toBe('Job created and queued successfully');

    $this->assertDatabaseHas('translation_jobs', [
        'source_lang_id' => $this->sourceLanguage->id,
        'target_lang_id' => $this->targetLanguage->id,
        'prompt_id' => $this->prompt->id,
    ]);
});

test('can create a job without product ids to fetch all products', function () {
    // Mock ProductDataProvider to return total count
    $mockProductProvider = Mockery::mock(ProductDataProviderInterface::class);
    $mockProductProvider->shouldReceive('getTotalCount')
        ->once()
        ->andReturn(250); // 250 products = 3 pages = 1 range message (pages 1-3)
    $this->app->instance(ProductDataProviderInterface::class, $mockProductProvider);

    // Mock RabbitMQ service - should receive 1 range message (5 pages per worker, 3 pages total)
    $mockRabbit = Mockery::mock(RabbitMQService::class);
    $mockRabbit->shouldReceive('publish')
        ->once()
        ->withArgs(function ($queue, $payload) {
            return $queue === 'product_fetch_queue'
                && $payload['type'] === 'range'
                && $payload['start_page'] === 1
                && $payload['end_page'] === 3
                && $payload['limit'] === 100
                && isset($payload['job_id']);
        });
    $this->app->instance(RabbitMQService::class, $mockRabbit);

    $response = $this->postJson('/api/jobs', [
        'source_lang_id' => $this->sourceLanguage->id,
        'target_lang_id' => $this->targetLanguage->id,
        'prompt_id' => $this->prompt->id,
    ]);

    $response->assertCreated();
});

test('creating a job without products queues correct number of range messages', function () {
    // Mock ProductDataProvider to return total count
    $mockProductProvider = Mockery::mock(ProductDataProviderInterface::class);
    $mockProductProvider->shouldReceive('getTotalCount')
        ->once()
        ->andReturn(1200); // 1200 products = 12 pages = 3 range messages (5+5+2)
    $this->app->instance(ProductDataProviderInterface::class, $mockProductProvider);

    // Track published ranges
    $publishedRanges = [];
    $mockRabbit = Mockery::mock(RabbitMQService::class);
    $mockRabbit->shouldReceive('publish')
        ->times(3)
        ->withArgs(function ($queue, $payload) use (&$publishedRanges) {
            if ($queue === 'product_fetch_queue' && $payload['type'] === 'range') {
                $publishedRanges[] = [
                    'start_page' => $payload['start_page'],
                    'end_page' => $payload['end_page'],
                ];

                return true;
            }

            return false;
        });
    $this->app->instance(RabbitMQService::class, $mockRabbit);

    $response = $this->postJson('/api/jobs', [
        'source_lang_id' => $this->sourceLanguage->id,
        'target_lang_id' => $this->targetLanguage->id,
        'prompt_id' => $this->prompt->id,
    ]);

    $response->assertCreated();
    expect($publishedRanges)->toHaveCount(3);
    expect($publishedRanges[0])->toBe(['start_page' => 1, 'end_page' => 5]);
    expect($publishedRanges[1])->toBe(['start_page' => 6, 'end_page' => 10]);
    expect($publishedRanges[2])->toBe(['start_page' => 11, 'end_page' => 12]);
});

test('creating a job with zero products queues no pages', function () {
    // Mock ProductDataProvider to return zero products
    $mockProductProvider = Mockery::mock(ProductDataProviderInterface::class);
    $mockProductProvider->shouldReceive('getTotalCount')
        ->once()
        ->andReturn(0);
    $this->app->instance(ProductDataProviderInterface::class, $mockProductProvider);

    // RabbitMQ should not receive any publish calls
    $mockRabbit = Mockery::mock(RabbitMQService::class);
    $mockRabbit->shouldNotReceive('publish');
    $this->app->instance(RabbitMQService::class, $mockRabbit);

    $response = $this->postJson('/api/jobs', [
        'source_lang_id' => $this->sourceLanguage->id,
        'target_lang_id' => $this->targetLanguage->id,
        'prompt_id' => $this->prompt->id,
    ]);

    $response->assertCreated();
});

test('cannot create a job with same source and target language', function () {
    $response = $this->postJson('/api/jobs', [
        'source_lang_id' => $this->sourceLanguage->id,
        'target_lang_id' => $this->sourceLanguage->id, // Same as source
        'prompt_id' => $this->prompt->id,
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['target_lang_id']);
});

test('cannot create a job with invalid language ids', function () {
    $response = $this->postJson('/api/jobs', [
        'source_lang_id' => 99999,
        'target_lang_id' => 99998,
        'prompt_id' => $this->prompt->id,
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['source_lang_id', 'target_lang_id']);
});

test('cannot create a job with invalid prompt id', function () {
    $response = $this->postJson('/api/jobs', [
        'source_lang_id' => $this->sourceLanguage->id,
        'target_lang_id' => $this->targetLanguage->id,
        'prompt_id' => 99999,
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['prompt_id']);
});

test('cannot create a job without required fields', function () {
    $response = $this->postJson('/api/jobs', []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['source_lang_id', 'target_lang_id', 'prompt_id']);
});
