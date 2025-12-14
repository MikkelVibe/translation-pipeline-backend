<?php

namespace App\Console\Commands;

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
            env('RABBITMQ_HOST'),
            env('RABBITMQ_PORT'),
            env('RABBITMQ_USER'),
            env('RABBITMQ_PASSWORD')
        );

        $channel = $connection->channel();
        $channel->queue_declare(self::CONSUME_QUEUE_NAME, false, false, false, false);

        $this->info('Waiting for messages on ' . self::CONSUME_QUEUE_NAME);

        $callback = function ($message) {
            $payload = json_decode($message->body, true);

            // Match the structure from ProductSyncWorker - data is nested under 'product'
            $product = $payload['product'] ?? null;

            if (!$product) {
                $this->warn('[TRANSLATION] Invalid message format - missing product data');

                return;
            }

            $id = $product['id'] ?? 'unknown';
            $this->info("[TRANSLATION] Processing: {$id}");

            // TODO: Call OpenAI GPT for translation + save to database
        };

        $channel->basic_consume(
            queue: self::CONSUME_QUEUE_NAME,
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
