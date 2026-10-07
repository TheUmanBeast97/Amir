<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Evento di squadra (tabella "events"): partita, allenamento o cena. */
class TeamEvent extends Model
{
    protected $table = 'events';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime'];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'match_id');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(EventResponse::class, 'event_id');
    }
}
