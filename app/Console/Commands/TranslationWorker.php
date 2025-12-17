<?php

namespace App\Console\Commands;

use App\DTOs\TranslationMessageDto;
use App\Enums\JobItemStatus;
use App\Enums\Queue;
use App\Models\JobItem;
use App\Models\Translation;
use Illuminate\Console\Command;
use PhpAmqpLib\Connection\AMQPStreamConnection;

class TranslationWorker extends Command
{
    private const CONSUME_QUEUE_NAME = 'product_translate_queue';

    protected $signature = 'worker:translation';

    protected $description = 'Consume translation jobs from RabbitMQ';

    public function handle()
    {
        $this->info('Starting translation worker...');

        $connection = new AMQPStreamConnection(
            config('queue.connections.rabbitmq.host'),
            config('queue.connections.rabbitmq.port'),
            config('queue.connections.rabbitmq.login'),
            config('queue.connections.rabbitmq.password')
        );

        $channel = $connection->channel();
        $channel->queue_declare(Queue::ProductTranslate->value, false, false, false, false);

        $this->info('Waiting for '.Queue::ProductTranslate->value.' message...');

        $callback = function ($message) {
            $timestamp = date('Y-m-d H:i:s');

            try {
                $messageData = TranslationMessageDto::fromArray(json_decode($message->body, true));
            } catch (\Exception $e) {
                echo "[{$timestamp}] Error parsing message: {$e->getMessage()}\n";

                return;
            }

            // Find the job item by ID
            $jobItem = JobItem::query()
                ->where('id', $messageData->jobItemId)
                ->first();

            if (!$jobItem) {
                echo "[{$timestamp}] Job item {$messageData->jobItemId} not found or not in queued status\n";

                return;
            }

            $jobItem->update(['status' => JobItemStatus::Processing]);

            try {
                $job = $jobItem->job()->with(['sourceLanguage', 'targetLanguage', 'prompt'])->first();

                $sourceText = $messageData->toSourceTextArray();

                $translatedText = $this->translateText(sourceText: $sourceText);

                // save in db and mark done
                Translation::create([
                    'job_item_id' => $jobItem->id,
                    'source_text' => $sourceText,
                    'translated_text' => $translatedText,
                    'language_id' => $job->target_lang_id,
                ]);

                $jobItem->update(['status' => JobItemStatus::Done]);

                echo "[{$timestamp}] Successfully processed job item {$messageData->jobItemId}\n";
            } catch (\Exception $e) {
                $jobItem->update([
                    'status' => JobItemStatus::Error,
                    'error_message' => $e->getMessage(),
                ]);

                echo "[{$timestamp}] Error processing job item {$messageData->jobItemId}: {$e->getMessage()}\n";
            }
        };

        $channel->basic_consume(
            queue: Queue::ProductTranslate->value,
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

    private function translateText(array $sourceText): array
    {
        // TODO: Call OpenAI GPT for translation
        // temp just prefix with [TRANSLATED]
        $result = [];

        foreach ($sourceText as $key => $value) {
            if ($value === null) {
                $result[$key] = null;
            } elseif (is_array($value)) {
                $result[$key] = array_map(fn($item) => "[TRANSLATED] {$item}", $value);
            } else {
                $result[$key] = "[TRANSLATED] {$value}";
            }
        }
        return $result;
    }
}
