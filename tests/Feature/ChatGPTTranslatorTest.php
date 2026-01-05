<?php

use App\Models\Job;
use App\Models\Language;
use App\Models\Prompt;
use App\Models\User;
use App\Services\Translation\ChatGPTTranslator;
use App\Services\Translation\TranslatorInterface;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->sourceLanguage = Language::factory()->create(['code' => 'en', 'name' => 'English']);
    $this->targetLanguage = Language::factory()->create(['code' => 'da', 'name' => 'Danish']);
    $this->prompt = Prompt::factory()->create([
        'name' => 'E-commerce Translation',
        'content' => 'Translate product content naturally for Danish e-commerce.',
    ]);
});

test('translator is bound in container', function () {
    $translator = app(TranslatorInterface::class);

    expect($translator)->toBeInstanceOf(ChatGPTTranslator::class);
});

test('builds prompt with base template and job instructions', function () {
    $job = Job::factory()
        ->for($this->user)
        ->for($this->sourceLanguage, 'sourceLanguage')
        ->for($this->targetLanguage, 'targetLanguage')
        ->for($this->prompt)
        ->create();

    // Use reflection to test the private buildPrompt method
    $translator = new ChatGPTTranslator(apiKey: 'test-api-key');
    $reflection = new ReflectionClass($translator);
    $method = $reflection->getMethod('buildPrompt');
    $method->setAccessible(true);

    $prompt = $method->invoke($translator, ['title' => 'Test'], $job);

    expect($prompt)->toContain('professional e-commerce translator');
    expect($prompt)->toContain('English');
    expect($prompt)->toContain('Danish');
    expect($prompt)->toContain('Additional instructions for this translation job');
    expect($prompt)->toContain('Translate product content naturally for Danish e-commerce.');
    expect($prompt)->toContain('"title": "Test"');
});

test('handles empty job prompt gracefully', function () {
    $emptyPrompt = Prompt::factory()->create([
        'name' => 'Empty Prompt',
        'content' => '',
    ]);

    $job = Job::factory()
        ->for($this->user)
        ->for($this->sourceLanguage, 'sourceLanguage')
        ->for($this->targetLanguage, 'targetLanguage')
        ->for($emptyPrompt)
        ->create();

    // Use reflection to test the private buildPrompt method
    $translator = new ChatGPTTranslator(apiKey: 'test-api-key');
    $reflection = new ReflectionClass($translator);
    $method = $reflection->getMethod('buildPrompt');
    $method->setAccessible(true);

    $prompt = $method->invoke($translator, ['title' => 'Test'], $job);

    // Should NOT contain the job-specific instructions header when prompt is empty
    expect($prompt)->not->toContain('Additional instructions for this translation job');
});

test('maps response correctly preserving null values', function () {
    $translator = new ChatGPTTranslator(apiKey: 'test-api-key');
    $reflection = new ReflectionClass($translator);
    $method = $reflection->getMethod('mapResponse');
    $method->setAccessible(true);

    $original = [
        'title' => 'Red bicycle',
        'description' => null,
        'metaTitle' => 'Buy now',
        'SEOKeywords' => null,
    ];

    $translated = [
        'title' => 'Rød cykel',
        'description' => null,
        'metaTitle' => 'Køb nu',
    ];

    $result = $method->invoke($translator, $translated, $original);

    expect($result['title'])->toBe('Rød cykel');
    expect($result['description'])->toBeNull();
    expect($result['metaTitle'])->toBe('Køb nu');
    expect($result['SEOKeywords'])->toBeNull();
});

test('maps response falls back to original for missing keys', function () {
    $translator = new ChatGPTTranslator(apiKey: 'test-api-key');
    $reflection = new ReflectionClass($translator);
    $method = $reflection->getMethod('mapResponse');
    $method->setAccessible(true);

    $original = [
        'title' => 'Red bicycle',
        'description' => 'A nice bike',
    ];

    // Translation is missing 'description'
    $translated = [
        'title' => 'Rød cykel',
    ];

    $result = $method->invoke($translator, $translated, $original);

    expect($result['title'])->toBe('Rød cykel');
    expect($result['description'])->toBe('A nice bike'); // Falls back to original
});

test('base prompt file exists', function () {
    $path = base_path('resources/prompts/translation-base.txt');

    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);
    expect($content)->toContain('{source_language}');
    expect($content)->toContain('{target_language}');
    expect($content)->toContain('{job_specific_instructions}');
    expect($content)->toContain('{product_json}');
});
