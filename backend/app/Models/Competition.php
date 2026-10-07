<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Competition extends Model
{
    /** Le amichevoli stanno in una competizione "finta" per stagione, senza torneo XFive. */
    public const KIND_FRIENDLY = 'amichevole';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_current' => 'boolean',
            'is_excluded' => 'boolean',
            'has_own_team' => 'boolean',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'synced_at' => 'datetime',
        ];
    }

    public function games(): HasMany
    {
        return $this->hasMany(Game::class);
    }

    /** Competizioni che contano per storico e statistiche: niente Serie A a 8 né amichevoli. */
    public function scopeCounted(Builder $query): Builder
    {
        return $query->where('is_excluded', false)->where('kind', '!=', self::KIND_FRIENDLY);
    }

    /** Giornate con almeno una partita che ha data/ora ufficiale. */
    public function scheduledRounds(): int
    {
        return (int) $this->games()
            ->whereNotNull('kickoff_at')
            ->where('status', '!=', Game::CANCELLED)
            ->whereNotNull('round')
            ->distinct()
            ->count('round');
    }
}
