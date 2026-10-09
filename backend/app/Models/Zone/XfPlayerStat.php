<?php

namespace App\Models\Zone;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una riga delle statistiche giocatori di un torneo come le pubblica XFive (type = score, top-player, discipline; nome abbreviato). */
class XfPlayerStat extends Model
{
    protected $table = 'xf_player_stats';

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
}
