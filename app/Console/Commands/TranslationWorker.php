<?php

namespace App\Console\Commands;

use App\DTOs\TranslationMessageDto;
use App\DTOs\TranslationPersistMessageDto;
use App\Enums\Queue;
use App\Services\RabbitMQService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Connection\AMQPStreamConnection;

/**
 * This worker handles pipeline logic.
   *
   * The TranslationWorker's responsibilities are:
   * 1) Consume ProductTranslate jobs from RabbitMQ
   * 2) Translate content (through translator service, OpenAI)
   * 3) Publish first results to ProductQE queue for quality evaluation
   * 4) Publish final results to ProductTranslationPersist queue for persistence
   * 5) Log errors and handle message ACK/NACK
   */
class TranslationWorker extends Command
{
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

      $consumeQueue = config('rabbitmq.queues.' . Queue::ProductTranslate->value);
      if (!\is_string($consumeQueue) || $consumeQueue === '') {
            $this->error('Translate queue is not configured (rabbitmq.queues.' . Queue::ProductTranslate->value . ')');
            return self::FAILURE;
      }

      $qeQueue = config('rabbitmq.queues.' . Queue::ProductQE->value);
      if (!\is_string($qeQueue) || $qeQueue === '') {
            $this->error('QE queue is not configured (rabbitmq.queues.' . Queue::ProductQE->value . ')');
            return self::FAILURE;
      }

      $persistQueue = config('rabbitmq.queues.' . Queue::ProductTranslationPersist->value);
      if (!\is_string($persistQueue) || $persistQueue === '') {
            $this->error('Persist queue is not configured (rabbitmq.queues.' . Queue::ProductTranslationPersist->value . ')');
            return self::FAILURE;
      }

      $connection = new AMQPStreamConnection(
            config('rabbitmq.host'),
            (int) config('rabbitmq.port'),
            config('rabbitmq.user'),
            config('rabbitmq.password')
      );

      $channel = $connection->channel();

      // Prefetch 1 = one message at a time per worker
      $channel->basic_qos(0, 1, false);

      $channel->queue_declare($consumeQueue, false, false, false, false);

      $this->info("Waiting for messages on {$consumeQueue}...");

      $callback = function ($message) use ($consumeQueue, $qeQueue, $persistQueue) {
            $timestamp = now()->format('Y-m-d H:i:s');

            $jobItemIdForError = null;
            $externalIdForError = null;

            try {
               $payload = json_decode($message->body, true, 512, JSON_THROW_ON_ERROR);
               if (!\is_array($payload)) {
                  throw new \RuntimeException('Message body is not a JSON object.');
               }

               // Expect payload structure from ProductSyncWorker
               $messageData = TranslationMessageDto::fromArray($payload);

               $jobItemIdForError = $messageData->jobItemId;
               $externalIdForError = $messageData->externalId;

               // These are optional metadata; keep null if not present
               $jobId = isset($payload['jobId']) ? (string) $payload['jobId'] : null;
               $productId = (string) $messageData->externalId;
               $sourceLanguage = (string) ($payload['sourceLanguage'] ?? 'da');
               $targetLanguage = (string) ($payload['targetLanguage'] ?? 'fi');

               $fields = $messageData->toSourceTextArray();

               $titlePreview = '';
               if (isset($fields['title']) && \is_string($fields['title'])) {
                  $titlePreview = substr($fields['title'], 0, 40);
               }

               $jobIdLog = $jobId ?? 'null';
               $this->line("[{$timestamp}] 🔄 Processing jobId={$jobIdLog} productId={$productId} {$titlePreview}");

               // 1) Prepare prompt + input JSON
               $inputJson = json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

               $promptTemplate = file_get_contents(
         resource_path('prompts/product_translation.txt')
               );

               if ($promptTemplate === false) {
                  throw new \RuntimeException('Translation prompt template could not be loaded');
               }

               $prompt = strtr($promptTemplate, [
                  '{{sourceLanguage}}' => $sourceLanguage,
                  '{{targetLanguage}}' => $targetLanguage,
                  '{{inputJson}}'      => $inputJson,
               ]);


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
                  throw new \RuntimeException("OpenAI error ({$response->status()}): " . $response->body());
               }

               $openAi = $response->json();

               // 3) Extract text (should be JSON)
               $text = $this->extractTextFromOpenAiResponse($openAi);

               // 4) Parse JSON from text
               $translatedFields = $this->tryParseJsonObject($text);

               // 5) Save raw + parsed to file (before persistence)
               // Best-effort: debug files must not crash the pipeline
               try {
                  $dir = storage_path('app/ai_test');
                  @mkdir($dir, 0777, true);

                  $base = ($jobId ?? 'nojob') . "_{$productId}_" . strtolower($targetLanguage);

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
                        file_put_contents("{$dir}/{$base}_openai_text.txt", (string) $text);
                  }
               } catch (\Throwable) {
                  // ignore debug write failures
               }

               // 6) Fail fast if JSON couldn't be parsed
               if ($translatedFields === null) {
                  throw new \RuntimeException(
                        'Could not parse valid JSON from OpenAI output. Saved raw output to storage/app/ai_test.'
                  );
               }

               // Publish QE job (src_fields + mt_fields contract)
               $qeFields = ['title', 'description', 'metaTitle', 'metaDescription'];
               $srcFields = [];
               $mtFields = [];

               foreach ($qeFields as $field) {
                  $srcFields[$field] = $fields[$field] ?? null;
                  $mtFields[$field] = $translatedFields[$field] ?? null;
               }

               $this->rabbit->publish(
                  queue: $qeQueue,
                  payload: [
                        'jobId' => $jobId,
                        'productId' => $productId,
                        'sourceLanguage' => $sourceLanguage,
                        'targetLanguage' => $targetLanguage,
                        'src_fields' => $srcFields,
                        'mt_fields' => $mtFields,
                  ]
               );

               // Publish persistence payload
               $persistDto = new TranslationPersistMessageDto(
                  jobItemId: $messageData->jobItemId,
                  externalId: $messageData->externalId,
                  sourceText: $fields,
                  translatedText: $translatedFields,
                  jobId: $jobId !== null && ctype_digit((string) $jobId) ? (int) $jobId : null,
                  languageId: null,
                  errorMessage: null,
                  errorStage: null
               );

               $this->rabbit->publish(
                  queue: $persistQueue,
                  payload: $persistDto->toArray()
               );

               // ACK success
               $message->ack();
               $this->info("✅ Done jobId={$jobIdLog} productId={$productId} -> QE+Persist published");
            } catch (\Throwable $e) {
               Log::error('TranslationWorker failed', [
                  'queue' => $consumeQueue,
                  'error' => $e->getMessage(),
                  'file' => $e->getFile(),
                  'line' => $e->getLine(),
                  'trace' => $e->getTraceAsString(),
                  'body'  => $message->body ?? null,
               ]);

               $this->error('Error: ' . $e->getMessage());

               // Publish error payload to persistence queue (so job can be marked Error)
               if ($jobItemIdForError !== null) {
                  try {
                        $errDto = new TranslationPersistMessageDto(
                           jobItemId: (int) $jobItemIdForError,
                           externalId: $externalIdForError,
                           sourceText: null,
                           translatedText: null,
                           jobId: null,
                           languageId: null,
                           errorMessage: $e->getMessage(),
                           errorStage: 'translation'
                        );

                        $this->rabbit->publish(
                           queue: $persistQueue,
                           payload: $errDto->toArray()
                        );
                  } catch (\Throwable) {
                        // ignore secondary failures
                  }
               }

               // NACK without requeue avoids infinite loops
               $message->nack(false, false);
            }
      };

      $channel->basic_consume(
            queue: $consumeQueue,
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
    * Attempts to extract output text from the OpenAI Responses API json.
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

      // 2) output_text (some clients/SDKs use this)
      if (isset($openAi['output_text']) && \is_string($openAi['output_text'])) {
            return $openAi['output_text'];
      }

      // 3) fallback: stringify entire json
      return json_encode($openAi, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
   }

   /**
    * Tries to parse a JSON object from text.
   * Returns array if OK, otherwise null.
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
