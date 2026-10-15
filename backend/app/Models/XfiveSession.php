<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** L'accesso a XFive di un utente dello staff: cookie di sessione cifrati (mai la password) e scadenza. */
class XfiveSession extends Model
{
    protected $guarded = [];

    protected $hidden = ['cookies'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
