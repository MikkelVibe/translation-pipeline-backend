<?php

namespace App\Http\Controllers\Api;

use App\Enums\JobItemStatus;
use App\Http\Controllers\Controller;
use App\Models\Job;
use App\Models\JobItem;
use App\Models\Translation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function metrics(): JsonResponse
    {
        $totalTranslations = Translation::count();
        $totalJobs = Job::count();

        // Active jobs: jobs that are still running
        // - Jobs with items that are queued or processing
        // - OR jobs where item count < total_items (still fetching products)
        $activeJobs = Job::where(function ($query) {
            $query->whereHas('items', fn ($q) => $q->whereIn('status', [JobItemStatus::Queued, JobItemStatus::Processing]))
                ->orWhereColumn(DB::raw('(SELECT COUNT(*) FROM job_items WHERE job_items.job_id = translation_jobs.id)'), '<', 'total_items');
        })->count();

        // Failed jobs: jobs with at least one error item
        $failedJobs = Job::whereHas('items', fn ($q) => $q->where('status', JobItemStatus::Error)
        )->count();

        // Completed jobs: jobs with all items done AND item count matches total_items
        $completedJobs = Job::whereDoesntHave('items', fn ($q) => $q->whereIn('status', [JobItemStatus::Queued, JobItemStatus::Processing, JobItemStatus::Error])
        )->whereHas('items')
            ->whereColumn(DB::raw('(SELECT COUNT(*) FROM job_items WHERE job_items.job_id = translation_jobs.id)'), '>=', 'total_items')
            ->count();

        // Queue size: items waiting to be processed
        $queueSize = JobItem::where('status', JobItemStatus::Queued)->count();

        // Error rate calculation
        $totalItems = JobItem::count();
        $errorItems = JobItem::where('status', JobItemStatus::Error)->count();
        $errorRate = $totalItems > 0 ? round(($errorItems / $totalItems) * 100, 2) : 0;

        return response()->json([
            'data' => [
                'totalTranslations' => $totalTranslations,
                'totalJobs' => $totalJobs,
                'activeJobs' => $activeJobs,
                'failedJobs' => $failedJobs,
                'completedJobs' => $completedJobs,
                'queueSize' => $queueSize,
                'errorRate' => $errorRate,
            ],
        ]);
    }

    public function charts(): JsonResponse
    {
        // Translations over time (last 24 hours, grouped by hour)
        // Use database-agnostic approach
        $driver = DB::connection()->getDriverName();

        // For tests (sqlite uses different than postgres)
        if ($driver === 'sqlite') {
            $translationsOverTime = Translation::select(
                DB::raw("strftime('%Y-%m-%d %H:00:00', created_at) as time"),
                DB::raw('COUNT(*) as count')
            )
                ->where('created_at', '>=', now()->subHours(24))
                ->groupBy(DB::raw("strftime('%Y-%m-%d %H:00:00', created_at)"))
                ->orderBy(DB::raw("strftime('%Y-%m-%d %H:00:00', created_at)"))
                ->get()
                ->map(fn ($item) => [
                    'time' => $item->time,
                    'count' => (int) $item->count,
                ]);
        } else {
            // PostgreSQL
            $translationsOverTime = Translation::select(
                DB::raw("to_char(date_trunc('hour', created_at), 'YYYY-MM-DD HH24:00:00') as time"),
                DB::raw('COUNT(*) as count')
            )
                ->where('created_at', '>=', now()->subHours(24))
                ->groupBy(DB::raw("date_trunc('hour', created_at)"))
                ->orderBy(DB::raw("date_trunc('hour', created_at)"))
                ->get()
                ->map(fn ($item) => [
                    'time' => $item->time,
                    'count' => (int) $item->count,
                ]);
        }

        // Job status distribution
        $jobStatusDistribution = [
            [
                'status' => 'completed',
                'count' => Job::whereDoesntHave('items', fn ($q) => $q->whereIn('status', [JobItemStatus::Queued, JobItemStatus::Processing, JobItemStatus::Error]))
                    ->whereHas('items')
                    ->whereColumn(DB::raw('(SELECT COUNT(*) FROM job_items WHERE job_items.job_id = translation_jobs.id)'), '>=', 'total_items')
                    ->count(),
            ],
            [
                'status' => 'running',
                'count' => Job::where(function ($query) {
                    $query->whereHas('items', fn ($q) => $q->whereIn('status', [JobItemStatus::Queued, JobItemStatus::Processing]))
                        ->orWhereColumn(DB::raw('(SELECT COUNT(*) FROM job_items WHERE job_items.job_id = translation_jobs.id)'), '<', 'total_items');
                })->whereDoesntHave('items', fn ($q) => $q->where('status', JobItemStatus::Error))
                    ->whereHas('items')
                    ->count(),
            ],
            [
                'status' => 'failed',
                'count' => Job::whereHas('items', fn ($q) => $q->where('status', JobItemStatus::Error)
                )->count(),
            ],
            [
                'status' => 'pending',
                'count' => Job::whereDoesntHave('items')->count(),
            ],
        ];

        return response()->json([
            'data' => [
                'translationsOverTime' => $translationsOverTime,
                'jobStatusDistribution' => $jobStatusDistribution,
            ],
        ]);
    }
}
