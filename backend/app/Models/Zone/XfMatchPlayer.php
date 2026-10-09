<?php

namespace App\Models\Zone;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una riga della distinta di un referto (side = home o away; player_id = pid globale, se abbinato alla rosa). */
class XfMatchPlayer extends Model
{
    protected $table = 'xf_match_players';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['mvp' => 'boolean'];
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(XfMatch::class, 'match_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(XfPlayer::class, 'player_id');
    }
}
