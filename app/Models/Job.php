<?php

namespace App\Models;

use App\Enums\JobItemStatus;
use App\Enums\JobStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Job extends Model
{
    use HasFactory;

    protected $table = 'translation_jobs';

    protected $fillable = [
        'user_id',
        'integration_id',
        'source_lang_id',
        'target_lang_id',
        'prompt_id',
        'total_items',
    ];

    protected $appends = [
        'status',
        'completed_items',
        'failed_items',
        'progress_percentage',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function sourceLanguage(): BelongsTo
    {
        return $this->belongsTo(Language::class, 'source_lang_id');
    }

    public function targetLanguage(): BelongsTo
    {
        return $this->belongsTo(Language::class, 'target_lang_id');
    }

    public function prompt(): BelongsTo
    {
        return $this->belongsTo(Prompt::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(JobItem::class);
    }

    public function getStatusAttribute(): JobStatus
    {
        $itemStatuses = $this->items()->pluck('status');

        // If any items have errors, job has failed
        if ($itemStatuses->contains(JobItemStatus::Error)) {
            return JobStatus::Failed;
        }

        // If all items are done, job is completed
        if ($itemStatuses->every(fn ($status) => $status === JobItemStatus::Done)) {
            return JobStatus::Completed;
        }

        // If any items are processing or queued, job is running
        if ($itemStatuses->contains(JobItemStatus::Processing) || $itemStatuses->contains(JobItemStatus::Queued)) {
            return JobStatus::Running;
        }

        // Default to pending
        return JobStatus::Pending;
    }

    public function getCompletedItemsAttribute(): int
    {
        return $this->items()->where('status', JobItemStatus::Done)->count();
    }

    public function getFailedItemsAttribute(): int
    {
        return $this->items()->where('status', JobItemStatus::Error)->count();
    }

    public function getProgressPercentageAttribute(): float
    {
        if ($this->total_items === 0) {
            return 0;
        }

        return round(($this->completed_items / $this->total_items) * 100, 2);
    }
}
