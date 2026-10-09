<?php

namespace App\Models\Zone;

use Illuminate\Database\Eloquent\Model;

/** Lo stato di una sezione della Mixed Zone: ultimo giro completo, conteggi e cursore da cui riprendere (chiave = nome della sezione). */
class XfSyncState extends Model
{
    protected $table = 'xf_sync_state';

    protected $primaryKey = 'section';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['synced_at' => 'datetime', 'counts' => 'array', 'cursor' => 'array'];
    }
}
