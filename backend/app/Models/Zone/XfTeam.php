<?php

namespace App\Models\Zone;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Una squadra iscritta a un torneo (id = teamId di XFive, cambia a ogni torneo). */
class XfTeam extends Model
{
    protected $table = 'xf_teams';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['staff' => 'array'];
    }

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(XfTournament::class, 'tournament_id');
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(XfClub::class, 'club_id');
    }

    public function players(): HasMany
    {
        return $this->hasMany(XfTeamPlayer::class, 'team_id');
    }
}
