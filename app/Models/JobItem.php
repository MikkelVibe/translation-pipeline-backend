<?php

namespace App\Models;

use App\Enums\JobItemStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class JobItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'job_id',
        'external_id',
        'status',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'status' => JobItemStatus::class,
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function translation(): HasOne
    {
        return $this->hasOne(Translation::class);
    }
}
