<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Player extends Model
{
    protected $guarded = [];

    protected $hidden = ['access_token'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'in_squad_list' => 'boolean',
            'medical_cert_expires_on' => 'date',
            'birth_date' => 'date',
            'xfive_profile' => 'array',
            'xfive_synced_at' => 'datetime',
            'scout_generated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Player $player) {
            if (empty($player->access_token)) {
                $player->access_token = Str::random(40);
            }
        });
    }

    protected function fullName(): Attribute
    {
        return Attribute::get(fn () => trim($this->first_name.' '.$this->last_name));
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function responses(): HasMany
    {
        return $this->hasMany(EventResponse::class);
    }

    public function stats(): HasMany
    {
        return $this->hasMany(MatchPlayerStat::class);
    }

    public function playerCharges(): HasMany
    {
        return $this->hasMany(PlayerCharge::class);
    }
}
