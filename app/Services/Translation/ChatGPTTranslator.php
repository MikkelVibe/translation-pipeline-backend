<?php

namespace App\Services\Translation;

use App\Models\Job;
use OpenAI;
use OpenAI\Client;

class ChatGPTTranslator implements TranslatorInterface
{
    private const BASE_PROMPT_PATH = 'resources/prompts/translation-base.txt';

    private Client $client;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'gpt-4o-mini',
    ) {
        $this->client = OpenAI::client($this->apiKey);
    }

    public function translate(array $content, Job $job): array
    {
        $job->loadMissing(['sourceLanguage', 'targetLanguage', 'prompt']);

        $prompt = $this->buildPrompt($content, $job);

        $response = $this->client->chat()->create([
            'model' => $this->model,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt,
                ],
            ],
            'response_format' => ['type' => 'json_object'],
        ]);

        $responseContent = $response->choices[0]->message->content ?? null;

        if (!$responseContent) {
            throw new \RuntimeException('Empty response from OpenAI API');
        }

        $translated = json_decode($responseContent, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Failed to parse OpenAI response as JSON: '.json_last_error_msg());
        }

        return $this->mapResponse($translated, $content);
    }

    private function buildPrompt(array $content, Job $job): string
    {
        $basePrompt = $this->loadBasePrompt();

        $sourceLang = $job->sourceLanguage->name;
        $targetLang = $job->targetLanguage->name;
        $jobSpecificInstructions = $this->formatJobSpecificInstructions($job);
        $contentJson = json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return str_replace(
            ['{source_language}', '{target_language}', '{job_specific_instructions}', '{product_json}'],
            [$sourceLang, $targetLang, $jobSpecificInstructions, $contentJson],
            $basePrompt
        );
    }

    private function loadBasePrompt(): string
    {
        $path = base_path(self::BASE_PROMPT_PATH);

        if (!file_exists($path)) {
            throw new \RuntimeException('Base translation prompt file not found: '.self::BASE_PROMPT_PATH);
        }

        return file_get_contents($path);
    }

    private function formatJobSpecificInstructions(Job $job): string
    {
        if (!$job->prompt || empty(trim($job->prompt->content))) {
            return '';
        }

        return "Additional instructions for this translation job:\n".$job->prompt->content;
    }

    private function mapResponse(array $translated, array $original): array
    {
        $result = [];

        foreach ($original as $key => $value) {
            if ($value === null) {
                $result[$key] = null;
            } elseif (isset($translated[$key])) {
                $result[$key] = $translated[$key];
            } else {
                // Fallback to original if key missing in translation
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
