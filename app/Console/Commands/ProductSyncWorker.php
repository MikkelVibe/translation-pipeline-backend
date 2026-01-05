<?php

namespace App\Console\Commands;

use App\DTOs\ProductDataDto;
use App\DTOs\ProductSyncMessageDto;
use App\Enums\JobItemStatus;
use App\Enums\Queue;
use App\Models\Job;
// use App\Models\JobItem;
use App\Services\DataProvider\ProductDataProviderInterface;
use App\Services\RabbitMQService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
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
            config("rabbitmq.host"),
            (int) config("rabbitmq.port"),
            config("rabbitmq.user"),
            config("rabbitmq.password")
        );

        $channel = $connection->channel();

        $consumeQueue = config('rabbitmq.queues.' . Queue::ProductFetch->value);
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

                $result = $this->validateCallback($payload);
                if (!$result) {
                    $message->nack(false, false);
                    return;
                }

                [$messageData, $job] = $result;
                if ($messageData->isIdsType()) {

                    $products = $this->productService->fetchProductsByIds($messageData->ids);

                    if ($products->isEmpty()) {
                        $message->nack(false, false);

                        echo "[PRODUCT SYNC] No products found for provided IDs\n";

                        return;
                    }
                    // Technically no validation on length
                    // $this->publishProductsToQueue($this->rabbit, $products); (USING BATCH NOW TODO:DELETE AFTER COMPLETED TESTS)
                    $this->processBatch($products, $job);
                } 
                
                if ($messageData->isRangeType()) {

                    $startPage = $messageData->startPage;
                    $endPage = $messageData->endPage;
                    $limit = (int) ($messageData->limit ?? 100);

                    echo "[PRODUCT SYNC] Processing pages {$startPage} to {$endPage}\n";

                    for ($page = $startPage; $page <= $endPage; $page++) {
                        $offset = ($page - 1) * $limit;

                        $products = $this->productService->fetchProducts(
                            limit: $limit,
                            offset: $offset
                        );

                        if ($products->isEmpty()) {
                            echo "[PRODUCT SYNC] No products found on page {$page}\n";

                            continue;
                        }

                        echo "[PRODUCT SYNC] Processing page ({$page}) with " . \count($products) ." products \n";
                        $this->processBatch($products, $job);
                        // $this->publishProductsToQueue($this->rabbit, $products); old publish
                    }
                }

                echo "[PRODUCT SYNC] Completed processing pages {$startPage} to {$endPage}\n";
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
    private function processBatch(Collection $products, Job $job): void
    {
        $publishQueue = config('rabbitmq.queues.' . Queue::ProductTranslate->value);

        if ($products->isEmpty()) {
            $this->info('[PRODUCT SYNC] No products could be found');
            return;
        }

        // validating products decreases chance of a batch failing and if a batch fails thats 999 wasted. Its very unlikely the batch will fail. But this just removes the potential failing products from ever hitting the batch decreasing the chance further.
        $validProducts = [];

        foreach ($products as $product) {
            if ($this->validateProduct($product)) {
                $validProducts[] = $product;
            }
        }

        if (!empty($validProducts)) {
            echo "[PRODUCT SYNC] No valid products to process\n";

            return;
        }

        try {
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
            $placeholders = implode(', ', array_fill(0,\count($validProducts), '(?, ?, ?, ?, ?)'));
            
            // TODO: check for injections
            
            // Raw sql to take advantage of RETURNING in postgres, it gives all generated ids which are needed for the message
            $insertedIds = DB::select(
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
                    'jobId' => $job->id,               // IMPORTANT: pass job id through
                    'product' => [
                        'id' => $product->id,
                        'title' => $product->title,
                        'description' => $product->description,
                        'metaTitle' => $product->metaTitle,
                        'metaDescription' => $product->metaDescription,
                        'SEOKeywords' => $product->SEOKeywords,
                    ],
                ];

            }
            
            $this->rabbit->publishBatch(
                $publishQueue,
                $payloads
            );

            // Update job total_items

            // Maybe fucked TODO:
            $job->increment('total_items', count($validProducts));
            
        } catch (\Exception $e) {
            echo "[PRODUCT SYNC] Batch failed: {$e->getMessage()}\n";
        }
    }
}
