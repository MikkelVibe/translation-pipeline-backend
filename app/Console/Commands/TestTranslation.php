<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Illuminate\Support\Str;

class TestTranslation extends Command
{
    protected $signature = 'test:enqueue-translation {--target=fi} {--source=da}';
    protected $description = 'Publish a test message to product_translate_queue. This will also trigger translation and QE';

    public function handle()
    {
        $queueName = config("rabbitmq.queues.product_translate");

        $jobId = (string) Str::uuid();
        $source = (string) $this->option('source');
        $target = (string) $this->option('target');

        $payload = [
            'jobId' => $jobId,
            'sourceLanguage' => $source,
            'targetLanguage' => $target,
            'product' => [
                'id' => 'TEST-123',
                'title' => 'Saft og Kraft - Økologisk Æblemost 1L',
                'description' => '<p>Friskpresset æblemost fra danske æbler. Uden tilsat sukker.</p>',
                'metaTitle' => 'Økologisk æblemost 1L | Saft og Kraft',
                'metaDescription' => 'Køb økologisk æblemost – friskpresset og uden tilsat sukker. Hurtig levering.',
                'SEOKeywords' => ['Økologisk', 'Æblemost'],
            ],
        ];


        $connection = new AMQPStreamConnection(
            config('rabbitmq.host'),
            (int) config('rabbitmq.port'),
            config('rabbitmq.user'),
            config('rabbitmq.password')
        );

        $channel = $connection->channel();
        $channel->queue_declare($queueName, false, false, false, false);

        $msg = new AMQPMessage(
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            [
                'content_type' => 'application/json',
                'delivery_mode' => 2, // persistent (ok selvom queue ikke er durable)
            ]
        );

        $channel->basic_publish($msg, '', $queueName);

        $channel->close();
        $connection->close();

        $this->info("✅ Published test jobId={$jobId} to {$queueName}");
        return self::SUCCESS;
    }
}