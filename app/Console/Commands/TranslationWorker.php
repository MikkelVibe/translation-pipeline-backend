<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpAmqpLib\connection\AMQPStreamConnection;

class TranslationWorker extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'worker:translation';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Consume translation jobs from RabbitMQ';

    /**
     * Execute the console command.
     */
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
        $channel->queue_declare('translation_queue', false, false, false, false);

        $this->info('Waiting for translation_queue message...');

        $callback = function($message) {
            $this->info(string: "Recieved message: {$message->body}");
            # herfra kalder vi OpenAI gpt + gemmer til db - men senere.
        };

        $channel->basic_consume(
            queue: 'translation_queue',
            consumer_tag: '',
            no_local: false,
            no_ack: true,
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
