<?php

use App\Enums\JobItemStatus;
use App\Models\Job;
use App\Models\JobItem;
use App\Models\Language;
use App\Models\Prompt;
use App\Models\Translation;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->sourceLanguage = Language::factory()->create();
    $this->targetLanguage = Language::factory()->create();
    $this->prompt = Prompt::factory()->create();
});

test('can get dashboard metrics', function () {
    // Create some test data
    $job = Job::factory()
        ->for($this->user)
        ->for($this->sourceLanguage, 'sourceLanguage')
        ->for($this->targetLanguage, 'targetLanguage')
        ->for($this->prompt)
        ->has(
            JobItem::factory()
                ->count(5)
                ->state(['status' => JobItemStatus::Done])
                ->has(Translation::factory()),
            'items'
        )
        ->has(
            JobItem::factory()
                ->count(2)
                ->state(['status' => JobItemStatus::Queued]),
            'items'
        )
        ->has(
            JobItem::factory()
                ->count(1)
                ->state(['status' => JobItemStatus::Error]),
            'items'
        )
        ->create(['total_items' => 8]);

    $response = $this->getJson('/api/dashboard/metrics');

    $response->assertOk()
        ->assertJsonStructure([
            'totalTranslations',
            'totalJobs',
            'activeJobs',
            'failedJobs',
            'completedJobs',
            'queueSize',
            'errorRate',
        ]);

    expect($response->json('totalTranslations'))->toBe(5);
    expect($response->json('totalJobs'))->toBe(1);
    expect($response->json('queueSize'))->toBe(2);
});

test('metrics returns zeros when no data', function () {
    $response = $this->getJson('/api/dashboard/metrics');

    $response->assertOk();

    expect($response->json('totalTranslations'))->toBe(0);
    expect($response->json('totalJobs'))->toBe(0);
    expect($response->json('activeJobs'))->toBe(0);
    expect($response->json('failedJobs'))->toBe(0);
    expect($response->json('completedJobs'))->toBe(0);
    expect($response->json('queueSize'))->toBe(0);
    expect($response->json('errorRate'))->toBe(0);
});

test('can get dashboard charts data', function () {
    $response = $this->getJson('/api/dashboard/charts');

    $response->assertOk()
        ->assertJsonStructure([
            'translationsOverTime',
            'jobStatusDistribution',
        ]);
});

test('charts returns job status distribution', function () {
    // Create completed job
    Job::factory()
        ->for($this->user)
        ->for($this->sourceLanguage, 'sourceLanguage')
        ->for($this->targetLanguage, 'targetLanguage')
        ->for($this->prompt)
        ->has(JobItem::factory()->count(2)->state(['status' => JobItemStatus::Done]), 'items')
        ->create(['total_items' => 2]);

    // Create failed job
    Job::factory()
        ->for($this->user)
        ->for($this->sourceLanguage, 'sourceLanguage')
        ->for($this->targetLanguage, 'targetLanguage')
        ->for($this->prompt)
        ->has(JobItem::factory()->state(['status' => JobItemStatus::Error]), 'items')
        ->create(['total_items' => 1]);

    $response = $this->getJson('/api/dashboard/charts');

    $response->assertOk();

    $distribution = collect($response->json('jobStatusDistribution'));

    expect($distribution->firstWhere('status', 'completed')['count'])->toBe(1);
    expect($distribution->firstWhere('status', 'failed')['count'])->toBe(1);
});

test('error rate is calculated correctly', function () {
    Job::factory()
        ->for($this->user)
        ->for($this->sourceLanguage, 'sourceLanguage')
        ->for($this->targetLanguage, 'targetLanguage')
        ->for($this->prompt)
        ->has(JobItem::factory()->count(8)->state(['status' => JobItemStatus::Done]), 'items')
        ->has(JobItem::factory()->count(2)->state(['status' => JobItemStatus::Error]), 'items')
        ->create(['total_items' => 10]);

    $response = $this->getJson('/api/dashboard/metrics');

    $response->assertOk();
    expect($response->json('errorRate'))->toEqual(20.0);
});
