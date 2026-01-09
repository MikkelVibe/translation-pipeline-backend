<?php

namespace App\Console\Commands;

use App\Enums\JobItemStatus;
use App\Enums\Queue;
use App\Messages\TranslationMessage;
use App\Models\JobItem;
use App\Models\Translation;
use App\Services\Translation\TranslatorInterface;
use Illuminate\Console\Command;
use PhpAmqpLib\Connection\AMQPStreamConnection;

class TranslationWorker extends Command
{
    private const CONSUME_QUEUE_NAME = 'product_translate_queue';

    protected $signature = 'worker:translation';

    protected $description = 'Consume translation jobs from RabbitMQ';

    public function handle(): int
    {
        $this->info('Starting translation worker...');

        $connection = new AMQPStreamConnection(
            config('queue.connections.rabbitmq.host'),
            config('queue.connections.rabbitmq.port'),
            config('queue.connections.rabbitmq.login'),
            config('queue.connections.rabbitmq.password'),
        );

        $channel = $connection->channel();
        $channel->queue_declare(Queue::ProductTranslate->value, false, false, false, false);

        $this->info('Waiting for '.Queue::ProductTranslate->value.' message...');

        // Resolve translator once when handle() is called, not at boot time
        $translator = app(TranslatorInterface::class);

        $callback = function ($message) use ($translator) {
            $timestamp = date('Y-m-d H:i:s');

            try {
                $messageData = TranslationMessage::fromArray(json_decode($message->body, true));
            } catch (\Exception $e) {
                echo "[{$timestamp}] Error parsing message: {$e->getMessage()}\n";
                $message->ack();

                return;
            }

            // Find the job item by ID
            $jobItem = JobItem::query()
                ->where('id', $messageData->jobItemId)
                ->first();

            if (!$jobItem) {
                echo "[{$timestamp}] Job item {$messageData->jobItemId} not found\n";
                $message->ack();

                return;
            }

            $jobItem->update(['status' => JobItemStatus::Processing]);

            try {
                $job = $jobItem->job()->with(['sourceLanguage', 'targetLanguage', 'prompt'])->first();

                $sourceText = $messageData->toSourceTextArray();

                $translatedText = $translator->translate($sourceText, $job);

                // save in db and mark done
                Translation::create([
                    'job_item_id' => $jobItem->id,
                    'source_text' => $sourceText,
                    'translated_text' => $translatedText,
                    'language_id' => $job->target_lang_id,
                ]);

                $jobItem->update(['status' => JobItemStatus::Done]);

                echo "[{$timestamp}] Successfully processed job item {$messageData->jobItemId}\n";

                $message->ack();
            } catch (\Exception $e) {
                $jobItem->update([
                    'status' => JobItemStatus::Error,
                    'error_message' => $e->getMessage(),
                ]);

                echo "[{$timestamp}] Error processing job item {$messageData->jobItemId}: {$e->getMessage()}\n";

                $message->ack();
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
}
