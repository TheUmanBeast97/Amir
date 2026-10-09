<?php

namespace App\Models\Zone;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una riga di classifica di un torneo (group = girone, «» se il torneo non ha gironi; values = Pt, G, V, N, P, F, S, +/-, FP). */
class XfStanding extends Model
{
    protected $table = 'xf_standings';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['values' => 'array'];
    }

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(XfTournament::class, 'tournament_id');
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(XfClub::class, 'club_id');
    }
}
