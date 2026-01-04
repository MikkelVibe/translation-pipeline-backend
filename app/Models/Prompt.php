<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Prompt extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'content',
    ];

    protected $appends = [
        'is_active',
    ];

    public function jobs(): HasMany
    {
        return $this->hasMany(Job::class);
    }

    // A prompt is considered active if it was used in a job within the last 7 days.
    public function getIsActiveAttribute(): bool
    {
        return $this->jobs()
            ->where('created_at', '>=', now()->subDays(7))
            ->exists();
    }
}
