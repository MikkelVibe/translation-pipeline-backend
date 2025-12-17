<?php

namespace App\Console\Commands;

use App\Services\DataProvider\ProductDataProviderInterface;
use App\Services\RabbitMQService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use PhpAmqpLib\Connection\AMQPStreamConnection;

class ProductSyncWorker extends Command
{
    // private const PUBLISH_QUEUE_NAME = 'product_translate_queue';
    // private const CONSUME_QUEUE_NAME = 'product_fetch_queue';

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
            config("rabbitmq.host"),
            (int) config("rabbitmq.port"),
            config("rabbitmq.user"),
            config("rabbitmq.password")
        );

        $channel = $connection->channel();

        $consumeQueue = config('rabbitmq.queues.product_fetch');
        $channel->queue_declare(
            $consumeQueue,
            false,
            false,
            false,
            false
        );

        $this->info("Waiting for messages on {$consumeQueue}...");

        $callback = function ($message) {
            try {
                $payload = json_decode($message->body, true);

                if (! \is_array($payload) || ! isset($payload['type'])) {
                    $message->nack(false, false);
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
                    $limit = (int) ($payload['limit'] ?? 100);

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

                $message->ack();

            } catch (\Throwable $e) {
                $message->nack(false, false);
            }
        };

        $channel->basic_consume(
            queue: $consumeQueue,
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
        $publishQueue = config('rabbitmq.queues.product_translate');

        if ($products->isEmpty()) {
            $this->info('[PRODUCT SYNC] No products could be found');
            return;
        }

        foreach ($products as $product) {
            $rabbit->publish(
                queue: $publishQueue,
                payload: [
                    "product" => [
                        "id" => $product->id,
                        "title" => $product->title,
                        "description" => $product->description,
                        "metaTitle" => $product->metaTitle,
                        "metaDescription" => $product->metaDescription,
                        "SEOKeywords" => $product->SEOKeywords,
                    ],
                ]
            );
        }
    }
}
