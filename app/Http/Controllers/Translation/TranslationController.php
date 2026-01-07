<?php

namespace App\Http\Controllers\Translation;

use App\Enums\JobStatus;
use App\Enums\Queue;
use App\Http\Controllers\Controller;
use App\Models\Job;
use App\Models\Language;
use App\Services\RabbitMQService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TranslationController extends Controller
{
    public function __construct(
        private RabbitMQService $rabbit
    ) {}

    public function publishIds(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['string'],
            'source_lang' => ['required', 'string'],
            'target_lang' => ['required', 'string'],
        ]);

        $ids = $request->ids;
        $totalProducts = count($ids);

        // Find languages by code
        $sourceLang = Language::where('code', $request->source_lang)->firstOrFail();
        $targetLang = Language::where('code', $request->target_lang)->firstOrFail();

        // Create job record
        $job = Job::create([
            'user_id' => 1, // hardcoded swag TODO: fix
            'source_lang_id' => $sourceLang->id,
            'target_lang_id' => $targetLang->id,
            'status' => JobStatus::Pending,
            'total_items' => 0,
        ]);

        $this->rabbit->publish(
            queue: Queue::ProductFetch->value,
            payload: [
                'type' => 'ids',
                'ids' => $ids,
                'job_id' => $job->id,
            ]
        );

        return response()->json([
            'message' => 'Product ID list queued successfully',
            'job_id' => $job->id,
            'job_type' => 'ids',
            'status' => JobStatus::Pending->value,
            'queued_at' => now()->toIso8601String(),
        ], 202);
    }

    // { "start_page": 1, "end_page": 5, "limit": 100, "source_lang": da_DK, "target_lang": en_GB}
    public function publishRange(Request $request): JsonResponse
    {
        $request->validate([
            'start_page' => ['required', 'integer', 'min:1'],
            'end_page' => ['required', 'integer', 'gte:start_page'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'source_lang' => ['required', 'string'],
            'target_lang' => ['required', 'string'],
        ]);

        $startPage = $request->start_page;
        $endPage = $request->end_page;
        $limit = $request->input('limit', 100);

        // Calculate totals
        $totalPages = $endPage - $startPage + 1;
        $estimatedProducts = $totalPages * $limit;

        // Find languages by code
        $sourceLang = Language::where('code', $request->source_lang)->firstOrFail();
        $targetLang = Language::where('code', $request->target_lang)->firstOrFail();

        // Create job record
        $job = Job::create([
            'user_id' => 1, // Hardcoded for now
            'source_lang_id' => $sourceLang->id,
            'target_lang_id' => $targetLang->id,
            'status' => JobStatus::Pending,
            'total_items' => 0,
        ]);

        for ($i = $startPage; $i <= $endPage; $i += 5) {
            $chunkStart = $i;
            $chunkEnd = min($i + 4, $endPage);

            $this->rabbit->publish(
                queue: Queue::ProductFetch->value,
                payload: [
                    'type' => 'range',
                    'start_page' => $chunkStart,
                    'end_page' => $chunkEnd,
                    'limit' => $limit,
                    'job_id' => $job->id,
                ]
            );
        }

        return response()->json([
            'message' => 'Page range job queued successfully',
            'job_id' => $job->id,
            'job_type' => 'range',
            'status' => JobStatus::Pending->value,
            'queued_at' => now()->toIso8601String(),
        ], 202);
    }
}
