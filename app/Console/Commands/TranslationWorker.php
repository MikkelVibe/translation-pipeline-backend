<?php

namespace App\Console\Commands;

use App\DTOs\TranslationMessageDto;
use App\Enums\JobItemStatus;
use App\Enums\Queue;
use App\Models\JobItem;
use App\Models\Translation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Services\RabbitMQService;

use PhpAmqpLib\Connection\AMQPStreamConnection;

class TranslationWorker extends Command
{
    protected $signature = 'worker:translation';

    protected $description = 'Consuming translation jobs from RabbitMQ';

    public function __construct(
        private readonly RabbitMQService $rabbit
    ) {
        parent::__construct();
    }

    public function handle()
    {
        $this->info('Starting translation worker...');

        $publishQueue = config('rabbitmq.queues.' . Queue::ProductTranslate->value);

        $connection = new AMQPStreamConnection(
            config("rabbitmq.host"),
            (int) config("rabbitmq.port"),
            config("rabbitmq.user"),
            config("rabbitmq.password")
        );

        $channel = $connection->channel();

        // Prefetch 1 = one message at a time per worker
        $channel->basic_qos(0, 1, null);

        // queue_declare(passive, durable, exclusive, auto_delete, nowait)
        $channel->queue_declare(
            $publishQueue,
            false,
            false,
            false,
            false
        );

        $this->info("Waiting for messages on {$publishQueue}...");

        $callback = function ($message) use ($publishQueue) {
            $timestamp = now()->format('Y-m-d H:i:s');

            $qeFieldList = ["title", "description", "metaTitle", "metaDescription"];

            try {
                $raw = json_decode($message->body, true, 512, JSON_THROW_ON_ERROR);
                if (!\is_array($raw)) {
                    throw new \RuntimeException("Message body is not a JSON object.");
                }

                // Normalizing payloads to have crosscompatible message shapes to both persistance and QE analysis
                $jobId = (string) ($raw["jobId"] ?? Str::uuid());
                $sourceLanguage = (string) ($raw["sourceLanguage"] ?? "da");
                $targetLanguage = (string) ($raw["targetLanguage"] ?? "fi");

                $jobItemId = $raw["job_item_id"] ?? $raw["jobItemId"] ?? null;

                if (isset($raw['product']) && \is_array($raw['product'])) {
                    $fields = $raw['product'];
                } else {
                    $fields = $raw;
                }

                if (!\is_array($fields)) {
                    $this->warn("[{$timestamp}] [TRANSLATION] Invalid message format - missing product fields");
                    $message->nack(false, false);
                    return;
                }

                $productId = (string) ($fields['id'] ?? $raw['productId'] ?? 'unknown');

                $titlePreview = '';
                if (isset($fields['title']) && \is_string($fields['title'])) {
                    $titlePreview = substr($fields['title'], 0, 40);
                }


                $this->line(
                    "[{$timestamp}] 🔄 Processing jobId={$jobId} productId={$productId} {$titlePreview}"
                );

                // Building prompt
                $inputJson = json_encode(
                    $fields,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );

                $prompt = <<<PROMPT
            
Task:
Translate product content from {$sourceLanguage} to {$targetLanguage} for an online webshop.

Input:
You will receive a JSON object containing product text fields.

Output requirements:
- Return ONLY valid JSON
- Preserve the exact JSON structure
- Do not add or remove fields

Translation rules:
- Translate only human-readable text
- Preserve HTML tags and structure exactly
- Do NOT translate:
  - Brand names
  - Product names
  - Model numbers
  - SKUs, IDs, EANs
  - Measurements or units
- Preserve numbers and technical values exactly

Edge cases:
- If a field is null, keep it null

Now translate the following product JSON:
{$inputJson}
PROMPT;
                // Call OpenAI
                $apiKey = env("OPENAI_API_KEY");
                if(!$apiKey) {
                    throw new \RuntimeException("OPENAI_API_KEY is mising in environment");
                }

                $model = env("OPENAI_MODEL", "gpt-4.1-mini");

                $response = Http::withToken($apiKey)
                    ->timeout(seconds: 60)
                    ->retry(times: 2, sleepMilliseconds: 500)
                    ->post("https://api.openai.com/v1/responses", [
                        "model" => $model,
                        "input" => $prompt,
                    ]);

                if (!$response->successful()) {
                    throw new \RuntimeException(
                        message: "OpenAI error ({$response->status()}): {$response->body()}"
                    );
                }

                $openAi = $response->json();

                // Extract + parse translated JSON
                $text = $this->extractTextFromOpenAiResponse($openAi);
                $translatedFields = $this->tryParseJsonObject($text);

                if (!\is_array($translatedFields)) {
                    $dir = storage_path("app/ai_test");
                    @mkdir(directory: $dir, permissions: 0777, recursive: true);

                    $base = "{$jobId}_{$productId}_" . strtolower($targetLanguage);
                    file_put_contents(
                        "{$dir}/{$base}_openai_raw.json", 
                        json_encode(
                            $openAi, 
                            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
                        )
                    );
                    file_put_contents("{$dir}/{$base}_openai_text.txt", (string) $text);

                    throw new \RuntimeException("Could not parse valid JSON from OpenAI output (saved debug files).");
                }

                $qeQueue = config("rabbitmq.queues.product_qe");
                if (!\is_string($qeQueue) || $qeQueue === '') {
                    throw new \RuntimeException("QE queue is not configured");
                }

                $srcFields = [];
                $mtFields  = [];
                foreach ($qeFieldList as $fieldItem) {
                    $srcFields[$fieldItem] = $fields[$fieldItem] ?? null;
                    $mtFields[$fieldItem]  = $translatedFields[$fieldItem] ?? null;
                }

                $this->rabbit->publish(
                    queue: $qeQueue,
                    payload: [
                        "jobId" => $jobId,
                        "productId" => $productId,
                        "sourceLanguage" => $sourceLanguage,
                        "targetLanguage" => $targetLanguage,
                        "src_fields" => $srcFields,
                        "mt_fields" => $mtFields,
                    ]
                );

                if (!empty($jobItemId)) {
                    $jobItem = JobItem::query()->where('id', $jobItemId)->first();

                    if ($jobItem) {
                        $jobItem->update(['status' => JobItemStatus::Processing]);

                        try {
                            $job = $jobItem->job()->with(['sourceLanguage', 'targetLanguage', 'prompt'])->first();

                            Translation::create([
                                'job_item_id' => $jobItem->id,
                                'source_text' => $fields,
                                'translated_text' => $translatedFields,
                                'language_id' => $job->target_lang_id ?? null,
                            ]);

                            $jobItem->update(['status' => JobItemStatus::Done]);
                        } catch (\Throwable $e) {
                            $jobItem->update([
                                'status' => JobItemStatus::Error,
                                'error_message' => $e->getMessage(),
                            ]);

                            Log::error("[TRANSLATION] Persistence failed for jobItemId={$jobItemId}", [
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                }

                $message->ack();
                $this->info("[{$timestamp}] ✅ Done jobId={$jobId} productId={$productId} -> QE published");

            } catch (\JsonException $e) {
                // Reject invalid Json message. No requeue.
                Log::warning("[TRANSLATION] Invalid JSON message", ['error' => $e->getMessage(), 'body' => $message->body ?? null]);
                $message->nack(false, false);
            } catch (\Throwable $e) {
                Log::error('TranslationWorker failed', [
                    'queue' => $publishQueue,
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'body' => $message->body ?? null,
                ]);

                $this->error("[{$timestamp}] Error: " . $e->getMessage());

                $message->nack(false, false);
            }
        };
    }

    private function handleMessage($message) {

        $payload = json_decode($message->body, true);

        if (!\is_array($payload)) {
            $this->warn('[TRANSLATION] Invalid JSON payload');
            return;
        }

        $fields = null;

        if (isset($payload['product']) && \is_array($payload['product'])) {
            $fields = $payload['product'];
        } else {
            if (isset($payload['id']) || isset($payload['job_item_id']) || isset($payload['title'])) {
                $fields = $payload;
            }
        }

        if (!\is_array($fields)) {
            $this->warn('[TRANSLATION] Invalid message format - missing product data');
            return;
        }

        $productId = (string)($fields['id'] ?? $payload['productId'] ?? 'unknown');
        $this->info("[TRANSLATION] Processing: {$productId}");

        $jobId = (string)($payload['jobId'] ?? Str::uuid());
        $sourceLanguage = (string)($payload['sourceLanguage'] ?? 'da');
        $targetLanguage = (string)($payload['targetLanguage'] ?? 'fi');

    }
}