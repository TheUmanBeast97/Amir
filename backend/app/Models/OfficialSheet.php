<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Una distinta ufficiale inviata a XFive per una partita. */
class OfficialSheet extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'match_id');
    }

    public function players(): HasMany
    {
        return $this->hasMany(OfficialSheetPlayer::class);
    }
}
