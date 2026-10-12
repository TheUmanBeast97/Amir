<?php

namespace App\Models\Zone;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un giocatore. Con `derived` falso è il profilo globale di XFive (id = pid di /it/player-info/{pid}/); con `derived`
 * vero è una persona ricavata dai referti (id da ZoneImporter::DERIVED_BASE), per chi XFive non dà un profilo.
 */
class XfPlayer extends Model
{
    protected $table = 'xf_players';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['profile' => 'array', 'synced_at' => 'datetime', 'derived' => 'boolean'];
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
