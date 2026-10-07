<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Charge extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['due_on' => 'date'];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function playerCharges(): HasMany
    {
        return $this->hasMany(PlayerCharge::class);
    }
}
