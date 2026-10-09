<?php

namespace App\Models\Zone;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Una partita XFive (id = mid di /it/match/{mid}/): dal calendario, con il referto se è stato letto. */
class XfMatch extends Model
{
    protected $table = 'xf_matches';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['kickoff_at' => 'datetime', 'played' => 'boolean', 'has_report' => 'boolean'];
    }

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(XfTournament::class, 'tournament_id');
    }

    public function homeClub(): BelongsTo
    {
        return $this->belongsTo(XfClub::class, 'home_club_id');
    }

    public function awayClub(): BelongsTo
    {
        return $this->belongsTo(XfClub::class, 'away_club_id');
    }

    public function players(): HasMany
    {
        return $this->hasMany(XfMatchPlayer::class, 'match_id');
    }
}
