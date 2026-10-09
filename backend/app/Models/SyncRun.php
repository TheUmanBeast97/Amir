<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncRun extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'stats' => 'array',
            'progress' => 'array', // {section, message, done, total}: l'ultimo passo fatto, per la sala di controllo
        ];
    }
}
