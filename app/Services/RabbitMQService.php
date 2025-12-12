<?php

namespace App\Services;

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
        if (! $this->connection) {
            $this->connection = new AMQPStreamConnection(
                host: env(key: 'RABBITMQ_HOST'),
                port: env(key: 'RABBITMQ_PORT'),
                user: env(key: 'RABBITMQ_USER'),
                password: env(key: 'RABBITMQ_PASSWORD')
            );
        }

        return $this->connection;
    }

    public function publish(string $queue, array $payload): void
    {
        $connection = $this->getConnection();
        $channel = $connection->channel();

        $channel->queue_declare(
            queue: $queue,
            passive: false,
            durable: false,
            exclusive: false,
            auto_delete: false
        );

        $message = new AMQPMessage(
            body: json_encode(
                value: $payload
            ));

        $channel->basic_publish(
            msg: $message,
            exchange: '',
            routing_key: $queue
        );

        $channel->close();
    }
}
