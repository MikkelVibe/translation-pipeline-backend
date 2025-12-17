<?php

namespace App\Console\Commands;

use App\Enums\JobItemStatus;
use App\Models\Job;
use App\Models\JobItem;
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
            $rabbit = $this->rabbit;
            $productService = $this->productService;

            $payload = json_decode($message->body, true);

            if (!is_array($payload) || ! isset($payload['type'])) {
                return;
            }

            // Get or create the job from payload
            $jobId = $payload['job_id'] ?? null;
            
            if (!$jobId) {
                echo "[PRODUCT SYNC] Error: No job_id provided in payload\n";
                return;
            }

            $job = Job::find($jobId);
            
            if (!$job) {
                echo "[PRODUCT SYNC] Error: Job {$jobId} not found\n";
                return;
            }

            if ($payload['type'] === 'ids') {

                $ids = $payload['ids'] ?? [];

                $products = $productService->fetchProductsByIds($ids);

                if ($products->isEmpty()) {
                    return;
                }

                foreach ($products as $product) {
                    // Create job item in database
                    $jobItem = JobItem::create([
                        'job_id' => $job->id,
                        'external_id' => $product->id,
                        'status' => JobItemStatus::Queued,
                    ]);

                    // Publish to translation queue
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

                    echo "[PRODUCT SYNC] Created job item {$jobItem->id} and published product ID {$product->id}\n";
                }
            }
            else if ($payload['type'] === 'range') {

                $startPage = $payload['start_page'];
                $endPage = $payload['end_page'];
                $limit = 100;

                for ($page = $startPage; $page <= $endPage; $page++) {

                    $offset = ($page - 1) * $limit;

                    $products = $productService->fetchProducts(
                        limit: $limit,
                        offset: $offset
                    );

                    foreach ($products as $product) {
                        // Create job item in database
                        $jobItem = JobItem::create([
                            'job_id' => $job->id,
                            'external_id' => $product->id,
                            'status' => JobItemStatus::Queued,
                        ]);
                        
                        // Publish to translation queue
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

                        echo "[PRODUCT SYNC] Created job item {$jobItem->id} for product ID {$product->id}\n";
                    }

                    echo "[PRODUCT SYNC] Published all products from page {$page}\n";
                }
            }
        };

        $channel->basic_consume(
            queue: 'product_fetch_queue',
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
