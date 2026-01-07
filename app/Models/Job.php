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

    public function getStatusAttribute(): string
    {
        $itemCount = $this->items()->count();

        // If no items yet, job is pending (still fetching from Shopware)
        if ($itemCount === 0) {
            return JobStatus::Pending->value;
        }

        // If not all items have been created yet, job is still running
        if ($itemCount < $this->total_items) {
            return JobStatus::Running->value;
        }

        $statuses = $this->items()->pluck('status');

        // If any items have errors, job has failed
        if ($statuses->contains(JobItemStatus::Error)) {
            return JobStatus::Failed->value;
        }

        // If any items are processing or queued, job is running
        if ($statuses->contains(JobItemStatus::Processing) || $statuses->contains(JobItemStatus::Queued)) {
            return JobStatus::Running->value;
        }

        // If all items are done, job is completed
        if ($statuses->every(fn ($status) => $status === JobItemStatus::Done)) {
            return JobStatus::Completed->value;
        }

        // Default to running (items exist but not all done)
        return JobStatus::Running->value;
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
