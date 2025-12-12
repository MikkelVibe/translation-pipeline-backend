<?php

namespace App\Console\Commands;

use App\Services\DataProvider\ProductDataProviderInterface;
use App\Services\RabbitMQService;
use Illuminate\Console\Command;
use PhpAmqpLib\Connection\AMQPStreamConnection;

class ProductSyncWorker extends Command
{
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

        $channel->queue_declare('product_fetch_queue', false, false, false, false);

        $this->info('Waiting for messages on product_fetch_queue…');

        $callback = function ($message) use (&$rabbit, &$productService) {
            echo "[PRODUCT SYNC] ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            echo "[PRODUCT SYNC] Message received\n";
            
            $rabbit = $this->rabbit;
            $productService = $this->productService;
            
            $payload = json_decode($message->body, true);

            if (! is_array($payload) || ! isset($payload['type'])) {
                echo "[PRODUCT SYNC] ❌ Invalid message format\n";
                return;
            }

            echo "[PRODUCT SYNC] Job Type: {$payload['type']}\n";

            /** -------------------------------------------------------
             ** TYPE = IDS
             ** ----------------------------------------------------- */
            if ($payload['type'] === 'ids') {

                $ids = $payload['ids'] ?? [];
                echo "[PRODUCT SYNC] Fetching " . count($ids) . " products by IDs\n";

                // Fetch all products by IDs in a single API call
                $products = $productService->fetchProductByIds($ids);

                if ($products->isEmpty()) {
                    echo "[PRODUCT SYNC] ⚠️  No products found\n";
                    return;
                }

                echo "[PRODUCT SYNC] ✓ Found {$products->count()} products\n";
                echo "[PRODUCT SYNC] Publishing to translation queue...\n";

                foreach ($products as $product) {
                    $rabbit->publish(
                        queue: 'product_translate_queue',
                        payload: [
                            'id' => $product->id,
                            'title' => $product->title,
                            'description' => $product->description,
                            'metaTitle' => $product->metaTitle,
                            'metaDescription' => $product->metaDescription,
                            'SEOKeywords' => $product->SEOKeywords,
                        ]
                    );
                }
                
                echo "[PRODUCT SYNC] ✓ Published {$products->count()} products\n";
            }

            if ($payload['type'] === 'range') {

                $startPage = $payload['start_page'];
                $endPage = $payload['end_page'];
                $limit = $payload['limit'] ?? 100;
                
                $totalPages = $endPage - $startPage + 1;

                echo "[PRODUCT SYNC] Processing page range: {$startPage}-{$endPage} ({$totalPages} pages, {$limit} per page)\n";

                $totalPublished = 0;

                for ($page = $startPage; $page <= $endPage; $page++) {

                    // Your fetchProducts() NEEDS offset — so convert page → offset
                    $offset = ($page - 1) * $limit;

                    echo "[PRODUCT SYNC] → Page {$page}/{$endPage}... ";

                    // Fetch using your existing method
                    $products = $productService->fetchProducts(
                        limit: $limit,
                        offset: $offset
                    );

                    if ($products->isEmpty()) {
                        echo "empty\n";
                        continue;
                    }

                    foreach ($products as $product) {
                        $rabbit->publish(
                            queue: 'product_translate_queue',
                            payload: [
                                'id' => $product->id,
                                'title' => $product->title,
                                'description' => $product->description,
                                'metaTitle' => $product->metaTitle,
                                'metaDescription' => $product->metaDescription,
                                'SEOKeywords' => $product->SEOKeywords,
                            ]
                        );
                    }

                    $totalPublished += $products->count();
                    echo "✓ {$products->count()} products\n";

                    // Clear products collection to free memory after processing each page
                    unset($products);
                    
                    // Force garbage collection to reclaim memory
                    if ($page % 10 === 0) {
                        gc_collect_cycles();
                        echo "[PRODUCT SYNC] 🧹 Memory cleanup (page {$page})\n";
                    }
                }
                
                echo "[PRODUCT SYNC] ✓ Completed: {$totalPublished} products published\n";
            }
            
            echo "[PRODUCT SYNC] ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        };

        $channel->basic_consume(
            queue: 'product_fetch_queue',
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
