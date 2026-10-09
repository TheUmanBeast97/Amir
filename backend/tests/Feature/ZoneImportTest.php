<?php

namespace Tests\Feature;

use App\Models\Zone\XfSyncState;
use App\Models\Zone\XfTournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Le tabelle xf_* della Mixed Zone e l'importazione dei dati XFive dall'archivio. */
class ZoneImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_zone_tables_exist_with_xfive_ids_as_keys(): void
    {
        $t = XfTournament::create(['id' => 187, 'season_id' => 8, 'name' => 'CITTADELLA', 'slug' => 'cittadella', 'sport' => 'Calcio a 8 - Maschile', 'format' => 8]);
        $this->assertSame(187, $t->refresh()->id);
        XfSyncState::create(['section' => 'zone-calendar', 'cursor' => ['tournament' => 187]]);
        $this->assertSame(['tournament' => 187], XfSyncState::find('zone-calendar')->cursor);
    }
}
