<?php

namespace App\Models\Zone;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Il profilo globale di un giocatore XFive (id = pid di /it/player-info/{pid}/). */
class XfPlayer extends Model
{
    protected $table = 'xf_players';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['profile' => 'array', 'synced_at' => 'datetime'];
    }

    public function teamPlayers(): HasMany
    {
        return $this->hasMany(XfTeamPlayer::class, 'player_id');
    }

    public function matchPlayers(): HasMany
    {
        return $this->hasMany(XfMatchPlayer::class, 'player_id');
    }
}
