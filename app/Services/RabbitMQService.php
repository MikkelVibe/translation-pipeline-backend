<?php

namespace App\Services;

use App\Messages\Contracts\Message;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class RabbitMQService
{
    private ?AMQPStreamConnection $connection = null;

    public function __construct()
    {
        //
    }

    public function __destruct()
    {
        if ($this->connection) {
            $this->connection->close();
        }
    }

    private function getConnection(): AMQPStreamConnection
    {
        if (!$this->connection) {
            $this->connection = new AMQPStreamConnection(
                host: env(key: 'RABBITMQ_HOST'),
                port: env(key: 'RABBITMQ_PORT'),
                user: env(key: 'RABBITMQ_USER'),
                password: env(key: 'RABBITMQ_PASSWORD')
            );
        }

        return $this->connection;
    }

    public function publish(Message $message): void
    {
        $queue = $message::queue()->value;

        $connection = $this->getConnection();
        $channel = $connection->channel();

        $channel->queue_declare(
            queue: $queue,
            passive: false,
            durable: false,
            exclusive: false,
            auto_delete: false
        );

        $amqpMessage = new AMQPMessage(
            body: json_encode($message->toArray())
        );

        $channel->basic_publish(
            msg: $amqpMessage,
            exchange: '',
            routing_key: $queue
        );

        $channel->close();
    }

    public function publishBatch(Message ...$messages): void
    {
        if (empty($messages)) {
            return;
        }

        // All messages must be of the same type
        $queue = $messages[0]::queue()->value;

        $connection = $this->getConnection();
        $channel = $connection->channel();

        $channel->queue_declare(
            queue: $queue,
            passive: false,
            durable: false,
            exclusive: false,
            auto_delete: false
        );

        foreach ($messages as $message) {
            $amqpMessage = new AMQPMessage(body: json_encode($message->toArray()));
            $channel->basic_publish($amqpMessage, '', $queue);
        }

        $channel->close();
    }
}
