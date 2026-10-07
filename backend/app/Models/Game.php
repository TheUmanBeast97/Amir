<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Una partita (tabella "matches"). "Match" è una parola riservata in PHP 8,
 * quindi il modello si chiama Game.
 */
class Game extends Model
{
    public const SCHEDULED = 'scheduled';
    public const TO_SCHEDULE = 'to_schedule';
    public const PLAYED = 'played';
    public const POSTPONED = 'postponed';
    public const CANCELLED = 'cancelled';

    protected $table = 'matches';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'kickoff_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'details_synced_at' => 'datetime',
            'details' => 'array',
            'callups_published' => 'boolean',
            'home_score' => 'integer',
            'away_score' => 'integer',
        ];
    }

    public function callups(): HasMany
    {
        return $this->hasMany(MatchCallup::class, 'match_id');
    }

    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    public function home(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'home_team_id');
    }

    public function away(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'away_team_id');
    }

    public function event(): HasOne
    {
        return $this->hasOne(TeamEvent::class, 'match_id');
    }

    public function lineup(): HasOne
    {
        return $this->hasOne(Lineup::class, 'match_id');
    }

    public function stats(): HasMany
    {
        return $this->hasMany(MatchPlayerStat::class, 'match_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', '!=', self::CANCELLED);
    }

    public function scopeInvolving(Builder $query, int $teamId): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('home_team_id', $teamId)
            ->orWhere('away_team_id', $teamId));
    }

    public function isProvisional(): bool
    {
        return $this->status === self::TO_SCHEDULE;
    }

    public function isPlayed(): bool
    {
        return $this->status === self::PLAYED
            && $this->home_score !== null
            && $this->away_score !== null;
    }

    /** Esito dal punto di vista di $teamId: W, D, L oppure null se non giocata. */
    public function resultFor(int $teamId): ?string
    {
        if (! $this->isPlayed()) {
            return null;
        }

        $isHome = $this->home_team_id === $teamId;
        $for = $isHome ? $this->home_score : $this->away_score;
        $against = $isHome ? $this->away_score : $this->home_score;

        return $for === $against ? 'D' : ($for > $against ? 'W' : 'L');
    }
}
