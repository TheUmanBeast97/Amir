<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un giocatore in una distinta ufficiale, con il numero di maglia usato quella volta. */
class OfficialSheetPlayer extends Model
{
    protected $guarded = [];

    public function sheet(): BelongsTo
    {
        return $this->belongsTo(OfficialSheet::class, 'official_sheet_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
