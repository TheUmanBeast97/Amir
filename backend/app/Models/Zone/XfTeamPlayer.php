<?php

namespace App\Models\Zone;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un giocatore nella rosa di una squadra in un torneo (tpid = id del giocatore nel torneo; player_id = pid globale, se abbinato). */
class XfTeamPlayer extends Model
{
    protected $table = 'xf_team_players';

    public $timestamps = false;

    protected $guarded = [];

    public function team(): BelongsTo
    {
        return $this->belongsTo(XfTeam::class, 'team_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(XfPlayer::class, 'player_id');
    }
}
