<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PhpAmqpLib\Connection\AMQPStreamConnection;

class TranslationWorker extends Command
{
    protected $signature = 'worker:translation';
    protected $description = 'Consume translation jobs from RabbitMQ, translate via OpenAI, and store results';

    public function handle()
    {
        $this->info('Starting translation worker...');

        $queueName = env('RABBITMQ_TRANSLATE_QUEUE', 'product_translate_queue');

        $connection = new AMQPStreamConnection(
            env('RABBITMQ_HOST', 'rabbitmq'),
            (int) env('RABBITMQ_PORT', 5672),
            env('RABBITMQ_USER', 'guest'),
            env('RABBITMQ_PASSWORD', 'guest')
        );

        $channel = $connection->channel();

        // Prefetch 1 = en message ad gangen pr worker
        $channel->basic_qos(null, 1, null);

        // queue_declare(passive, durable, exclusive, auto_delete, nowait)
        $channel->queue_declare($queueName, false, false, false, false);

        $this->info("Waiting for messages on {$queueName}...");

        $callback = function ($message) use ($queueName) {
            $timestamp = now()->format('Y-m-d H:i:s');

            try {
                $payload = json_decode($message->body, true, 512, JSON_THROW_ON_ERROR);

                // Robust defaults
                $jobId = (string) ($payload['jobId'] ?? Str::uuid());
                $productId = $payload['productId'] ?? ($payload['id'] ?? 'unknown');
                $sourceLanguage = (string) ($payload['sourceLanguage'] ?? 'da');
                $targetLanguage = (string) ($payload['targetLanguage'] ?? 'fi');

                // Vi forventer at I sender "fields" (det I faktisk vil oversætte)
                // Hvis ikke, prøver vi fallback til hele payload’en (men det er ikke optimalt).
                $fields = $payload['fields'] ?? null;
                if (!is_array($fields)) {
                    $fields = $payload; // fallback
                }

                $titlePreview = '';
                if (isset($fields['title']) && is_string($fields['title'])) {
                    $titlePreview = substr($fields['title'], 0, 40);
                } elseif (isset($payload['title']) && is_string($payload['title'])) {
                    $titlePreview = substr($payload['title'], 0, 40);
                }

                $this->line("[{$timestamp}] 🔄 Processing jobId={$jobId} productId={$productId} {$titlePreview}");

                // 1) Forbered prompt + input JSON
                $inputJson = json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

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

                // 2) Kald OpenAI
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
                    throw new \RuntimeException("OpenAI error ({$response->status()}): " . $response->body());
                }

                $openAi = $response->json();

                // 3) Udtræk tekst (som gerne skulle være JSON)
                $text = $this->extractTextFromOpenAiResponse($openAi);

                // 4) Parse JSON fra tekst (hvis modellen har skrevet noget ekstra, prøver vi at “trimme”)
                $translatedFields = $this->tryParseJsonObject($text);

                // 5) Gem både raw + parsed til fil (før DB)
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
                        json_encode($translatedFields, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                    );
                } else {
                    // Hvis parsing fejler, gem rå tekst også
                    file_put_contents("{$dir}/{$base}_openai_text.txt", (string) $text);
                }

                // 6) Hvis vi ikke kunne parse JSON, så betragter vi det som fejl (så I fanger det tidligt)
                if ($translatedFields === null) {
                    throw new \RuntimeException("Could not parse valid JSON from OpenAI output. Saved raw output to storage/app/ai_test.");
                }

                // TODO (næste step): gem i DB + publish til næste queue

                // ACK
                $message->ack();

                $this->info("✅ Done jobId={$jobId} productId={$productId} -> saved to storage/app/ai_test");
            } catch (\Throwable $e) {
                Log::error('TranslationWorker failed', [
                    'queue' => $queueName,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'body'  => $message->body ?? null,
                ]);

                $this->error("Error: " . $e->getMessage());

                // NACK uden requeue = undgår infinite restart loop på samme message
                $message->nack(false, false);
            }
        };

        $channel->basic_consume(
            queue: $queueName,
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
        // Responses API kan variere lidt, så vi prøver flere mulige steder.
        // 1) output[0].content[0].text
        if (isset($openAi['output'][0]['content'][0]['text']) && is_string($openAi['output'][0]['content'][0]['text'])) {
            return $openAi['output'][0]['content'][0]['text'];
        }

        // 2) output_text (nogle klienter/SDK’er bruger dette)
        if (isset($openAi['output_text']) && is_string($openAi['output_text'])) {
            return $openAi['output_text'];
        }

        // 3) fallback: stringify hele json (så vi i det mindste gemmer noget)
        return json_encode($openAi, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    /**
     * Prøver at parse et JSON object fra en tekst.
     * Returnerer array hvis OK, ellers null.
     */
    private function tryParseJsonObject(string $text): ?array
    {
        $text = trim($text);

        // Direkte JSON?
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Hvis modellen har skrevet ekstra tekst, prøv at finde første {...} blok.
        $firstBrace = strpos($text, '{');
        $lastBrace = strrpos($text, '}');

        if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
            $maybe = substr($text, $firstBrace, $lastBrace - $firstBrace + 1);
            $decoded2 = json_decode($maybe, true);
            if (is_array($decoded2)) {
                return $decoded2;
            }
        }

        return null;
    }
}
