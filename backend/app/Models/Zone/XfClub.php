<?php

namespace App\Models\Zone;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Un club XFive (id = clubId di XFive, quello di /it/team-h/{id}/). */
class XfClub extends Model
{
    protected $table = 'xf_clubs';

    public $incrementing = false;

    protected $guarded = [];

    public function teams(): HasMany
    {
        return $this->hasMany(XfTeam::class, 'club_id');
    }

    public function homeMatches(): HasMany
    {
        return $this->hasMany(XfMatch::class, 'home_club_id');
    }

    public function awayMatches(): HasMany
    {
        return $this->hasMany(XfMatch::class, 'away_club_id');
    }
}
