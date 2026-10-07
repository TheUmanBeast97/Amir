<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Lineup extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'slots' => 'array',
            'bench' => 'array',
            'is_published' => 'boolean',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'match_id');
    }
}
