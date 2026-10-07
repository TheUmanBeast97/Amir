<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Quota assegnata a un singolo giocatore (voce di saldo). */
class PlayerCharge extends Model
{
    protected $guarded = [];

    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class);
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function paidCents(): int
    {
        return (int) $this->payments->sum('amount_cents');
    }

    public function balanceCents(): int
    {
        return $this->amount_cents - $this->paidCents();
    }
}
