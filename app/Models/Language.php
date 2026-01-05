<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Language extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
    ];

    public function jobsAsSource(): HasMany
    {
        return $this->hasMany(Job::class, 'source_lang_id');
    }

    public function jobsAsTarget(): HasMany
    {
        return $this->hasMany(Job::class, 'target_lang_id');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(Translation::class);
    }
}
