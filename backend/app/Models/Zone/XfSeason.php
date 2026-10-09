<?php

namespace App\Models\Zone;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Una stagione XFive (id = sid di XFive, label «2026/2027»). */
class XfSeason extends Model
{
    protected $table = 'xf_seasons';

    public $incrementing = false;

    protected $guarded = [];

    public function tournaments(): HasMany
    {
        return $this->hasMany(XfTournament::class, 'season_id');
    }
}
