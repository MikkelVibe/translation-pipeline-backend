<?php

namespace App\Console\Commands;

use App\DTOs\TranslationPersistMessageDto;
use App\Enums\JobItemStatus;
use App\Enums\Queue;
use App\Models\JobItem;
use App\Models\Translation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Connection\AMQPStreamConnection;

/**
 * This worker handles domain/state logic.
 *
 * The TranslationPersistWorker's responsibilities are:
 * 1) Find a JobItem
 * 2) Update status (Queued -> Processing -> Done/Error)
 * 3) Persist the translation to the database
 * 4) Log errors if something goes wrong
 *
 * NOTE:
 * - This worker does not call OpenAI or publish QE jobs
 * - It persists results coming from TranslationWorker
 */
class TranslationPersistWorker extends Command
{
    protected $signature = 'worker:translation-persist';
    protected $description = 'Persist translated results and update job item status';

    public function handle(): int
    {
        $this->info('Starting translation persistence worker...');

        // Use Enum + rabbitmq config to get the actual queue name
        $consumeQueue = config('rabbitmq.queues.' . Queue::ProductTranslationPersist->value);

        if (!\is_string($consumeQueue) || $consumeQueue === '') {
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

        $callback = function ($message) use ($consumeQueue) {
            $timestamp = now()->format('Y-m-d H:i:s');

            try {
                $payload = json_decode($message->body, true, 512, JSON_THROW_ON_ERROR);
                if (!\is_array($payload)) {
                    echo "[{$timestamp}] [PERSIST] Message body is not a JSON object\n";
                    $message->ack();
                    return;
                }

                $dto = TranslationPersistMessageDto::fromArray($payload);

                $jobItem = JobItem::query()->where('id', $dto->jobItemId)->first();
                if (!$jobItem) {
                    echo "[{$timestamp}] [PERSIST] JobItem {$dto->jobItemId} not found\n";
                    $message->ack();
                    return;
                }

                // Error payload from TranslationWorker
                if ($dto->isError()) {
                    $jobItem->update([
                        'status' => JobItemStatus::Error,
                        'error_message' => (string) $dto->errorMessage,
                    ]);

                    echo "[{$timestamp}] [PERSIST] Marked JobItem {$dto->jobItemId} as Error (stage={$dto->errorStage})\n";
                    $message->ack();
                    return;
                }

                if (!\is_array($dto->sourceText) || !\is_array($dto->translatedText)) {
                    $jobItem->update([
                        'status' => JobItemStatus::Error,
                        'error_message' => 'Persist payload missing source_text or translated_text',
                    ]);

                    echo "[{$timestamp}] [PERSIST] Invalid payload for JobItem {$dto->jobItemId} (missing texts)\n";
                    $message->ack();
                    return;
                }

                $jobItem->update(['status' => JobItemStatus::Processing]);

                try {
                    $languageId = $dto->languageId;

                    if ($languageId === null) {
                        $job = $jobItem->job()->first();
                        $languageId = $job?->target_lang_id;
                    }

                    // Avoid duplicate inserts if a retry or double-publish happens
                    $existing = Translation::query()->where('job_item_id', $jobItem->id)->first();

                    if ($existing) {
                        $existing->update([
                            'source_text' => $dto->sourceText,
                            'translated_text' => $dto->translatedText,
                            'language_id' => $languageId,
                        ]);
                    } else {
                        Translation::create([
                            'job_item_id' => $jobItem->id,
                            'source_text' => $dto->sourceText,
                            'translated_text' => $dto->translatedText,
                            'language_id' => $languageId,
                        ]);
                    }

                    $jobItem->update(['status' => JobItemStatus::Done]);

                    echo "[{$timestamp}] [PERSIST] ✅ Persisted translation for JobItem {$dto->jobItemId}\n";
                    $message->ack();
                } catch (\Throwable $e) {
                    $jobItem->update([
                        'status' => JobItemStatus::Error,
                        'error_message' => $e->getMessage(),
                    ]);

                    Log::error('[PERSIST] Failed to persist translation', [
                        'queue' => $consumeQueue,
                        'job_item_id' => $dto->jobItemId,
                        'error' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ]);

                    // Ack to avoid infinite loops
                    $message->ack();
                }
            } catch (\JsonException $e) {
                Log::warning('[PERSIST] Invalid JSON message', [
                    'queue' => $consumeQueue,
                    'error' => $e->getMessage(),
                    'body' => $message->body ?? null,
                ]);

                $message->ack();
            } catch (\Throwable $e) {
                Log::error('[PERSIST] Unexpected failure', [
                    'queue' => $consumeQueue,
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'body' => $message->body ?? null,
                ]);

                $message->ack();
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
}
