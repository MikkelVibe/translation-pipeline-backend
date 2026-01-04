<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Setting extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'max_retries',
        'retry_delay',
        'score_threshold',
        'manual_check_threshold',
    ];

    protected function casts(): array
    {
        return [
            'max_retries' => 'integer',
            'retry_delay' => 'integer',
            'score_threshold' => 'integer',
            'manual_check_threshold' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
