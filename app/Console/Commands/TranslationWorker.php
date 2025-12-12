<?php

namespace App\Console\Commands;

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
            env('RABBITMQ_HOST'),
            env('RABBITMQ_PORT'),
            env('RABBITMQ_USER'),
            env('RABBITMQ_PASSWORD')
        );

        $channel = $connection->channel();
        $channel->queue_declare('product_translate_queue', false, false, false, false);

        $this->info('Waiting for product_translate_queue message...');

        $callback = function ($message) {
            $data = json_decode($message->body, true);
            
            $timestamp = date('Y-m-d H:i:s');
            $id = $data['id'] ?? 'unknown';
            $title = isset($data['title']) ? substr($data['title'], 0, 40) : 'N/A';
            
            echo "[{$timestamp}] 🔄 Processing: {$id} - {$title}...\n";
            
            // TODO: Call OpenAI GPT for translation + save to database
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
}
