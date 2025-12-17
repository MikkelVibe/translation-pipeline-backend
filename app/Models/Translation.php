<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Translation extends Model
{
    use HasFactory;

    protected $primaryKey = 'job_item_id';

    protected $fillable = [
        'job_item_id',
        'source_text',
        'translated_text',
        'language_id',
    ];

    protected function casts(): array
    {
        return [
            'source_text' => 'array',
            'translated_text' => 'array',
        ];
    }

    public function jobItem(): BelongsTo
    {
        return $this->belongsTo(JobItem::class);
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }
}
