<?php

namespace App\Models\Zone;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un documento (modulistica) di un torneo: indirizzo sul CDN di XFive e titolo. */
class XfDocument extends Model
{
    protected $table = 'xf_documents';

    public $timestamps = false;

    protected $guarded = [];

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(XfTournament::class, 'tournament_id');
    }
}
