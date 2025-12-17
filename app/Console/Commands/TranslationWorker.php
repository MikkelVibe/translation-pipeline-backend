<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Services\RabbitMQService;

use PhpAmqpLib\Connection\AMQPStreamConnection;

class TranslationWorker extends Command
{
    // private const CONSUME_QUEUE_NAME = 'product_translate_queue';

    protected $signature = 'worker:translation';

    protected $description = 'Consuming translation jobs from RabbitMQ';

    public function __construct(
        private readonly RabbitMQService $rabbit
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Starting translation worker...');

        $publishQueue = config("rabbitmq.queues.product_translate");

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

            try {
                $payload = json_decode($message->body, true, 512, JSON_THROW_ON_ERROR);

                // MASTER: Expect payload structure from ProductSyncWorker
                $product = $payload['product'] ?? null;
                if (!\is_array($product)) {
                    $this->warn('[TRANSLATION] Invalid message format - missing product data');
                    $message->nack(false, false);
                    return;
                }

                // IDs + language defaults
                $jobId = (string) ($payload['jobId'] ?? Str::uuid());
                $productId = $product['id'] ?? ($payload['productId'] ?? 'unknown');
                $sourceLanguage = (string) ($payload['sourceLanguage'] ?? 'da');
                $targetLanguage = (string) ($payload['targetLanguage'] ?? 'fi');

                // Fields to translate (prefer explicit 'fields', fallback to product)
                $fields = $payload['fields'] ?? null;
                if (!\is_array($fields)) {
                    $fields = $product;
                }

                $titlePreview = '';
                if (isset($fields['title']) && \is_string($fields['title'])) {
                    $titlePreview = substr($fields['title'], 0, 40);
                }


                $this->line(
                    "[{$timestamp}] 🔄 Processing jobId={$jobId} productId={$productId} {$titlePreview}"
                );

                // 1) Prepare prompt + input JSON
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

SEO rules:
- Meta titles must be natural, concise, and suitable for search engines
- Meta descriptions should be clear, informative, and mildly sales-oriented
- Do NOT invent features, materials, certifications, or specifications
- Do NOT exaggerate or add marketing claims not present in the source
- Prefer clarity and correctness over keyword stuffing

Edge cases:
- If a field is null, keep it null
- If text is already in {$targetLanguage}, return it unchanged
- If text contains mixed languages, translate only the {$sourceLanguage} parts

Now translate the following product JSON:
{$inputJson}
PROMPT;

                // 2) Call OpenAI
                $apiKey = env('OPENAI_API_KEY');
                if (!$apiKey) {
                    throw new \RuntimeException('OPENAI_API_KEY is missing in environment');
                }

                $model = env('OPENAI_MODEL', 'gpt-4.1-mini');

                $response = Http::withToken($apiKey)
                    ->timeout(60)
                    ->retry(2, 500)
                    ->post('https://api.openai.com/v1/responses', [
                        'model' => $model,
                        'input' => $prompt,
                    ]);

                if (!$response->successful()) {
                    throw new \RuntimeException(
                        "OpenAI error ({$response->status()}): " . $response->body()
                    );
                }

                $openAi = $response->json();

                // 3) Extract text (should be JSON)
                $text = $this->extractTextFromOpenAiResponse($openAi);

                // 4) Parse JSON from text
                $translatedFields = $this->tryParseJsonObject($text);

                // CometQE
                $qeQueue = config("rabbitmq.queues.product_qe");

                // Populating src_fields and mt_fields via sweep
                $qeFields = ["title", "description", "metaTitle", "metaDescription"];
                $srcFields = [];
                $mtFields = [];
                foreach ($qeFields as $field) {
                    $srcFields[$field] = $fields[$field] ?? null;
                    $mtFields[$field] = $translatedFields[$field] ?? null;
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

                // 5) Save raw + parsed to file (before DB)
                $dir = storage_path('app/ai_test');
                @mkdir($dir, 0777, true);

                $base = "{$jobId}_{$productId}_" . strtolower($targetLanguage);

                file_put_contents(
                    "{$dir}/{$base}_openai_raw.json",
                    json_encode($openAi, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                );

                file_put_contents(
                    "{$dir}/{$base}_input_fields.json",
                    json_encode($fields, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                );

                if ($translatedFields !== null) {
                    file_put_contents(
                        "{$dir}/{$base}_translated_fields.json",
                        json_encode(
                            $translatedFields,
                            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
                        )
                    );
                } else {
                    file_put_contents(
                        "{$dir}/{$base}_openai_text.txt",
                        (string) $text
                    );
                }

                // 6) Fail fast if JSON couldn't be parsed
                if ($translatedFields === null) {
                    throw new \RuntimeException(
                        'Could not parse valid JSON from OpenAI output. ' .
                        'Saved raw output to storage/app/ai_test.'
                    );
                }

                // TODO: save in DB + publish to next queue
                
                $qeQueue = config("rabbitmq.queues.product_qe");
                if (!\is_string($qeQueue) || $qeQueue === "") {
                    throw new \RuntimeException("QE-queue is not configured.");
                }

                // Fields for Scoring with CometQE
                $qeFields = [
                    "title",
                    "description",
                    "metaTitle",
                    "metaDescription",
                ];
                $srcFields = [];
                $mtFields = [];

                foreach ($qeFields as $field) {
                    $srcFields[$field] = $fields[$field] ?? null;
                    $mtFields[$field] = $translatedFields[$field] ?? null;
                }

                // Publish QE job to RabbitMQ
                // TODO: Persistence, retries
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

                // ACK success
                $message->ack();

                $this->info(
                    "✅ Done jobId={$jobId} productId={$productId} -> saved to storage/app/ai_test"
                );
            } catch (\Throwable $e) {
                Log::error('TranslationWorker failed', [
                    'queue' => $publishQueue,
                    'error' => $e->getMessage(),
                    'file'=> $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                    'body'  => $message->body ?? null,
                ]);

                $this->error('Error: ' . $e->getMessage());

                // NACK without requeue avoids infinite loops
                $message->nack(false, false);
            }
        };

        $channel->basic_consume(
            queue: $publishQueue,
            consumer_tag: '',
            no_local: false,
            no_ack: false,
            exclusive: false,
            nowait: false,
            callback: $callback
        );

        while ($channel->is_consuming()) {
            $channel->wait();
        }

        $channel->close();
        $connection->close();

        return self::SUCCESS;
    }

    /**
     * Forsøger at udtrække output-tekst fra OpenAI Responses API json.
     */
    private function extractTextFromOpenAiResponse(array $openAi): string
    {
        // 1) output[0].content[0].text
        if (
            isset($openAi['output'][0]['content'][0]['text']) &&
            \is_string($openAi['output'][0]['content'][0]['text'])
        ) {
            return $openAi['output'][0]['content'][0]['text'];
        }

        // 2) output_text (nogle klienter/SDK’er bruger dette)
        if (isset($openAi['output_text']) && \is_string($openAi['output_text'])) {
            return $openAi['output_text'];
        }

        // 3) fallback: stringify hele json
        return json_encode(
            $openAi,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) ?: '';
    }

    /**
     * Prøver at parse et JSON object fra en tekst.
     * Returnerer array hvis OK, ellers null.
     */
    private function tryParseJsonObject(string $text): ?array
    {
        $text = trim($text);

        // Direct JSON?
        $decoded = json_decode($text, true);
        if (\is_array($decoded)) {
            return $decoded;
        }

        // Try extracting first {...} block
        $firstBrace = strpos($text, '{');
        $lastBrace  = strrpos($text, '}');

        if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
            $maybe = substr($text, $firstBrace, $lastBrace - $firstBrace + 1);
            $decoded2 = json_decode($maybe, true);

            if (\is_array($decoded2)) {
                return $decoded2;
            }
        }

        return null;
    }
}
