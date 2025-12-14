<?php

namespace App\Console\Commands;

use App\Services\DataProvider\ProductDataProviderInterface;
use App\Services\RabbitMQService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use PhpAmqpLib\Connection\AMQPStreamConnection;

class ProductSyncWorker extends Command
{
    private const PUBLISH_QUEUE_NAME = 'product_translate_queue';

    private const CONSUME_QUEUE_NAME = 'product_fetch_queue';

    protected $signature = 'worker:product-sync';

    protected $description = 'Fetch products and publish them to RabbitMQ';

    public function __construct(
        private readonly ProductDataProviderInterface $productService,
        private readonly RabbitMQService $rabbit
    ) {
        parent::__construct();
    }

    public function handle()
    {
        $this->info('Starting product sync worker...');

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

            if (! is_array($payload) || ! isset($payload['type'])) {
                return;
            }

            if ($payload['type'] === 'ids') {
                $ids = $payload['ids'] ?? [];

                $products = $this->productService->fetchProductsByIds($ids);

                $this->publishProductsToQueue($this->rabbit, $products);

                $this->info('[PRODUCT SYNC] Published all products');

            } elseif ($payload['type'] === 'range') {

                $startPage = $payload['start_page'];
                $endPage = $payload['end_page'];
                $limit = 100;

                for ($page = $startPage; $page <= $endPage; $page++) {
                    $offset = ($page - 1) * $limit;

                    $products = $this->productService->fetchProducts(
                        limit: $limit,
                        offset: $offset
                    );

                    $this->publishProductsToQueue($this->rabbit, $products);

                    $this->info("[PRODUCT SYNC] Published all products from page {$page}\n");
                }
            }
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

    private function publishProductsToQueue(RabbitMQService $rabbit, Collection $products)
    {
        if ($products->isEmpty()) {
            $this->info('[PRODUCT SYNC] No products could be found');
            return;
        }

        foreach ($products as $product) {
            $rabbit->publish(
                queue: self::PUBLISH_QUEUE_NAME,
                payload: [
                    'product' => $product,
                ]
            );
        }
    }
}
