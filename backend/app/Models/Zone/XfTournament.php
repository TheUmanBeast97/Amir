<?php

namespace App\Models\Zone;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Un torneo XFive (id = tid di XFive). */
class XfTournament extends Model
{
    protected $table = 'xf_tournaments';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['calendar_synced_at' => 'datetime', 'standings_synced_at' => 'datetime', 'stats_synced_at' => 'datetime', 'teams_synced_at' => 'datetime'];
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(XfSeason::class, 'season_id');
    }

    public function matches(): HasMany
    {
        return $this->hasMany(XfMatch::class, 'tournament_id');
    }

    public function teams(): HasMany
    {
        return $this->hasMany(XfTeam::class, 'tournament_id');
    }

    public function standings(): HasMany
    {
        return $this->hasMany(XfStanding::class, 'tournament_id');
    }

    public function playerStats(): HasMany
    {
        return $this->hasMany(XfPlayerStat::class, 'tournament_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(XfDocument::class, 'tournament_id');
    }
}
