<?php

namespace App\Http\Controllers\Translation;

use App\Http\Controllers\Controller;
use App\Services\RabbitMQService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TranslationController extends Controller
{
   public function __construct(
      private RabbitMQService $rabbit
   ) {
   }

   public function publishIds(Request $request): JsonResponse
   {
      $request->validate([
         'ids' => ['required', 'array'],
         'ids.*' => ['string'],
      ]);

      $ids = $request->ids;
      $totalProducts = count($ids);

      $this->rabbit->publish(
         queue: 'product_fetch_queue',
         payload: [
            'type' => 'ids',
            'ids' => $ids,
         ]
      );

      return response()->json([
         'message' => 'Product ID list queued successfully',
         'job_type' => 'ids',
         'status' => 'queued',
         'queued_at' => now()->toIso8601String(),
      ], 202);
   }

   // { "start_page": 1, "end_page": 5, "limit": 100}
   public function publishRange(Request $request): JsonResponse
   {
      $request->validate([
         'start_page' => ['required', 'integer', 'min:1'],
         'end_page' => ['required', 'integer', 'gte:start_page'],
         'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
      ]);

      $startPage = $request->start_page;
      $endPage = $request->end_page;
      $limit = $request->input('limit', 100);

      // Calculate totals
      $totalPages = $endPage - $startPage + 1;
      $estimatedProducts = $totalPages * $limit;
      
      // Calculate how many worker batches we'll create (chunks of 5 pages)
      $batchesCreated = 0;
      $batches = [];

      for ($i = $startPage; $i <= $endPage; $i += 5) {
         $chunkStart = $i;
         $chunkEnd = min($i + 4, $endPage);
         $pagesInBatch = $chunkEnd - $chunkStart + 1;

         $this->rabbit->publish(
            queue: 'product_fetch_queue',
            payload: [
               'type' => 'range',
               'start_page' => $chunkStart,
               'end_page' => $chunkEnd,
               'limit' => $limit,
            ]
         );

         $batchesCreated++;
         $batches[] = [
            'batch_number' => $batchesCreated,
            'pages' => "{$chunkStart}-{$chunkEnd}",
            'page_count' => $pagesInBatch,
            'estimated_products' => $pagesInBatch * $limit,
         ];
      }

      return response()->json([
         'message' => 'Page range job queued successfully',
         'job_type' => 'range',
         'status' => 'queued',
         'queued_at' => now()->toIso8601String(),
      ], 202);
   }
}
