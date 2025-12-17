<?php

namespace App\Console\Commands;

use App\DTOs\ProductDataDto;
use App\DTOs\ProductSyncMessageDto;
use App\Enums\JobItemStatus;
use App\Enums\Queue;
use App\Models\Job;
use App\Models\JobItem;
use App\Services\DataProvider\ProductDataProviderInterface;
use App\Services\RabbitMQService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use function PHPUnit\Framework\isEmpty;

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

        $channel->queue_declare(Queue::ProductFetch->value, false, false, false, false);

        $callback = function ($message) {
            $rabbit = $this->rabbit;
            $productService = $this->productService;

            $payload = json_decode($message->body, true);

            $result = $this->validateCallback($payload);
            if (!$result) {
                return;
            }

            [$messageData, $job] = $result;

            if ($messageData->isIdsType()) {
                $products = $productService->fetchProductsByIds($messageData->ids);

                if ($products->isEmpty()) {
                    echo "[PRODUCT SYNC] No products found for provided IDs\n";

                    return;
                }
                // Technically no validation on length
                $this->processBatch($products, $job, $rabbit);
            } elseif ($messageData->isRangeType()) {
                $startPage = $messageData->startPage;
                $endPage = $messageData->endPage;
                $limit = $messageData->limit ?? 100;

                echo "[PRODUCT SYNC] Processing pages {$startPage} to {$endPage}\n";

                for ($page = $startPage; $page <= $endPage; $page++) {
                    $offset = ($page - 1) * $limit;

                    $products = $productService->fetchProducts(
                        limit: $limit,
                        offset: $offset
                    );

                    if ($products->isEmpty()) {
                        echo "[PRODUCT SYNC] No products found on page {$page}\n";

                        continue;
                    }

                    echo "[PRODUCT SYNC] Processing page {$page} with " . count($products) . " products\n";
                    $this->processBatch($products, $job, $rabbit);
                }

                echo "[PRODUCT SYNC] Completed processing pages {$startPage} to {$endPage}\n";
            }
        };

        $channel->basic_consume(
            queue: Queue::ProductFetch->value,
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

    private function validateProduct(ProductDataDto $product): bool
    {
        $isEmpty = empty($product->id);

        if ($isEmpty) {
            echo "[PRODUCT SYNC] Error validating product";
        }

        return !$isEmpty;
        
    }

    private function validateCallback($payload): ?array
    {
        try {
            $messageData = ProductSyncMessageDto::fromArray($payload);
        } catch (\Exception $e) {
            echo "[PRODUCT SYNC] Error parsing message: {$e->getMessage()}\n";
            return null;
        }

        $job = Job::find($messageData->jobId);

        if (!$job) {
            echo "[PRODUCT SYNC] Error: Job {$messageData->jobId} not found\n";
            return null;
        }

        return [$messageData, $job];
    }

    /**
     * @param Collection<int, ProductDataDto> $products
     */
    private function processBatch(Collection $products, Job $job, RabbitMQService $rabbit): void
    {
        if ($products->isEmpty()) {
            return;
        }

        // validating products decreases chance of a batch failing and if a batch fails thats 999 wasted. Its very unlikely the batch will fail. But this just removes the potential failing products from ever hitting the batch decreasing the chance further.
        $validProducts = [];

        foreach ($products as $product) {
            if ($this->validateProduct($product)) {
                $validProducts[] = $product;
            }
        }

        if (empty($validProducts)) {
            echo "[PRODUCT SYNC] No valid products to process\n";

            return;
        }

        try {
            // Prepare values for bulk insert
            $values = [];
            $now = now();

            foreach ($validProducts as $product) {
                $values[] = $job->id;
                $values[] = $product->id;
                $values[] = JobItemStatus::Queued->value;
                $values[] = $now;
                $values[] = $now;
            }

            // Build placeholders: (?, ?, ?, ?, ?), (?, ?, ?, ?, ?), ...
            $placeholders = implode(', ', array_fill(0, count($validProducts), '(?, ?, ?, ?, ?)'));


            // TODO: check for injections

            // Raw sql to take advantage of RETURNING in postgres, it gives all generated ids which are needed for the message
            $insertedIds = \DB::select(
                "INSERT INTO job_items (job_id, external_id, status, created_at, updated_at) 
                 VALUES {$placeholders} 
                 RETURNING id",
                $values
            );

            // Add the generated id and create the payloads
            $payloads = [];
            foreach ($validProducts as $index => $product) {
                $payloads[] = [
                    'job_item_id' => $insertedIds[$index]->id,
                    'id' => $product->id,
                    'title' => $product->title,
                    'description' => $product->description,
                    'metaTitle' => $product->metaTitle,
                    'metaDescription' => $product->metaDescription,
                    'SEOKeywords' => $product->SEOKeywords,
                ];
            }

            $rabbit->publishBatch(Queue::ProductTranslate->value, $payloads);

            // Update job total_items


            // Maybe fucked TODO:
            $job->increment('total_items', count($validProducts));

        } catch (\Exception $e) {
            echo "[PRODUCT SYNC] Batch failed: {$e->getMessage()}\n";
        }
    }
}