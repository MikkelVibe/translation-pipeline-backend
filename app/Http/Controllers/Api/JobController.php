<?php

namespace App\Http\Controllers\Api;

use App\Enums\JobItemStatus;
use App\Enums\JobStatus;
use App\Http\Controllers\Controller;
use App\Messages\ProductSyncMessage;
use App\Models\Job;
use App\Services\DataProvider\ProductDataProviderInterface;
use App\Services\RabbitMQService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JobController extends Controller
{
    private const PAGE_LIMIT = 100;

    private const PAGES_PER_WORKER = 5;

    public function __construct(
        private RabbitMQService $rabbit,
        private ProductDataProviderInterface $productDataProvider,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Job::with(['sourceLanguage', 'targetLanguage', 'prompt'])
            ->when($request->status, function ($query, $status) {
                // Filter by computed status
                $status = JobStatus::tryFrom($status);
                if ($status === null) {
                    return $query;
                }

                return match ($status) {
                    JobStatus::Failed => $query->whereHas('items', fn ($q) => $q->where('status', JobItemStatus::Error)
                    ),
                    JobStatus::Completed => $query->whereDoesntHave('items', fn ($q) => $q->whereIn('status', [JobItemStatus::Queued, JobItemStatus::Processing, JobItemStatus::Error])
                    )->whereHas('items'),
                    JobStatus::Running => $query->whereHas('items', fn ($q) => $q->whereIn('status', [JobItemStatus::Queued, JobItemStatus::Processing])
                    )->whereDoesntHave('items', fn ($q) => $q->where('status', JobItemStatus::Error)
                    ),
                    JobStatus::Pending => $query->whereDoesntHave('items'),
                };
            })
            ->when($request->language, function ($query, $language) {
                $query->whereHas('targetLanguage', fn ($q) => $q->where('code', $language));
            })
            ->latest();

        $jobs = $query->paginate($request->per_page ?? 20);

        return response()->json($jobs);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'source_lang_id' => ['required', 'integer', 'exists:languages,id'],
            'target_lang_id' => ['required', 'integer', 'exists:languages,id', 'different:source_lang_id'],
            'prompt_id' => ['required', 'integer', 'exists:prompts,id'],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['string'],
        ]);

        // Logic for ids or all
        $productIds = $validated['product_ids'] ?? [];
        $totalItems = 0;

        if (!empty($productIds)) {
            $totalItems = count($productIds);
        } else {
            $totalItems = $this->productDataProvider->getTotalCount();
        }

        $job = Job::create([
            'user_id' => 1, // TODO: Get from auth
            'source_lang_id' => $validated['source_lang_id'],
            'target_lang_id' => $validated['target_lang_id'],
            'prompt_id' => $validated['prompt_id'],
            'status' => JobStatus::Pending,
            'total_items' => $totalItems,
        ]);

        if (!empty($productIds)) {
            $this->rabbit->publish(
                ProductSyncMessage::forIds($job->id, $productIds)
            );
        } else {
            $totalPages = (int) ceil($totalItems / self::PAGE_LIMIT);

            // Each worker gets PAGES_PER_WORKER pages to process
            // Workers will gracefully handle edge cases when products are empty
            for ($startPage = 1; $startPage <= $totalPages; $startPage += self::PAGES_PER_WORKER) {
                $endPage = min($startPage + self::PAGES_PER_WORKER - 1, $totalPages);

                $this->rabbit->publish(
                    ProductSyncMessage::forRange($job->id, $startPage, $endPage, self::PAGE_LIMIT)
                );
            }
        }

        return response()->json([
            'message' => 'Job created and queued successfully',
        ], 201);
    }

    // Show job details with relations.
    public function show(Job $job): JsonResponse
    {
        $job->load(['sourceLanguage', 'targetLanguage', 'prompt']);

        return response()->json($job);
    }

    // Get paginated job items with translations.
    public function items(Request $request, Job $job): JsonResponse
    {
        $items = $job->items()
            ->with('translation')
            ->paginate($request->per_page ?? 48);

        return response()->json($items);
    }

    // Get job items with error status.
    public function errors(Request $request, Job $job): JsonResponse
    {
        $items = $job->items()
            ->where('status', JobItemStatus::Error)
            ->with('translation')
            ->paginate($request->per_page ?? 48);

        return response()->json($items);
    }
}
