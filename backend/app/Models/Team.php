<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Team extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_own' => 'boolean'];
    }

    public static function own(): ?self
    {
        return static::query()->where('is_own', true)->first();
    }

    public static function ownOrFail(): self
    {
        return static::own() ?? abort(503, 'Squadra non configurata: esegui "php artisan db:seed" o una sincronizzazione.');
    }
}
