<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['paid_at' => 'date'];
    }

    public function playerCharge(): BelongsTo
    {
        return $this->belongsTo(PlayerCharge::class);
    }
}
