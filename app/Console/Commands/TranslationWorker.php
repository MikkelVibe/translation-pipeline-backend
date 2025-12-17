<?php

namespace App\Console\Commands;

use App\Enums\JobItemStatus;
use App\Models\JobItem;
use App\Models\Translation;
use Illuminate\Console\Command;
use PhpAmqpLib\Connection\AMQPStreamConnection;

class TranslationWorker extends Command
{
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
        $channel->queue_declare('product_translate_queue', false, false, false, false);

        $this->info('Waiting for product_translate_queue message...');

        $callback = function ($message) {
            $data = json_decode($message->body, true);

            $timestamp = date('Y-m-d H:i:s');
            $id = $data['id'] ?? 'unknown';
            $title = isset($data['title']) ? substr($data['title'], 0, 40) : 'N/A';

            echo "[{$timestamp}] Processing: {$id} - {$title}...\n";

            // Find the job item by external_id
            $jobItem = JobItem::query()
                ->where('external_id', $id)
                ->where('status', JobItemStatus::Queued)
                ->first();

            if (! $jobItem) {
                echo "[{$timestamp}] No queued job item found for external_id: {$id}\n";

                return;
            }

            // Update status to processing
            $jobItem->update(['status' => JobItemStatus::Processing]);

            try {
                // Load the job with relationships
                $job = $jobItem->job()->with(['sourceLanguage', 'targetLanguage', 'prompt'])->first();

                // Prepare the text to translate
                $sourceText = $this->prepareSourceText($data);

                // TODO: Call OpenAI GPT for translation
                $translatedText = "[TRANSLATED] {$sourceText}";

                // Save the translation
                Translation::create([
                    'job_item_id' => $jobItem->id,
                    'source_text' => $sourceText,
                    'translated_text' => $translatedText,
                    'language_id' => $job->target_lang_id,
                ]);

                // Update status to done
                $jobItem->update(['status' => JobItemStatus::Done]);

                echo "[{$timestamp}] Successfully processed: {$id}\n";
            } catch (\Exception $e) {
                // Update status to error with error message
                $jobItem->update([
                    'status' => JobItemStatus::Error,
                    'error_message' => $e->getMessage(),
                ]);

                echo "[{$timestamp}] Error processing {$id}: {$e->getMessage()}\n";
            }
        };

        $channel->basic_consume(
            queue: 'product_translate_queue',
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

    private function prepareSourceText(array $data): string
    {
        $parts = [];

        if (isset($data['title'])) {
            $parts[] = "Title: {$data['title']}";
        }

        if (isset($data['description'])) {
            $parts[] = "Description: {$data['description']}";
        }

        if (isset($data['metaTitle'])) {
            $parts[] = "Meta Title: {$data['metaTitle']}";
        }

        if (isset($data['metaDescription'])) {
            $parts[] = "Meta Description: {$data['metaDescription']}";
        }

        if (isset($data['SEOKeywords'])) {
            $parts[] = "SEO Keywords: {$data['SEOKeywords']}";
        }

        return implode("\n\n", $parts);
    }
}
