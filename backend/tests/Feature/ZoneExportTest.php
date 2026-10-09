<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Zone\XfMatch;
use App\Models\Zone\XfMatchPlayer;
use App\Models\Zone\XfSyncState;
use App\Models\Zone\XfTeamPlayer;
use App\Models\Zone\XfTournament;
use App\Services\Zone\ZoneExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** L'export dell'archivio XFive a pezzi gzip e il caricamento dei pezzi online (POST /zone/import, GET /zone/status). */
class ZoneExportTest extends TestCase
{
    use RefreshDatabase;

    private string $out;

    protected function setUp(): void
    {
        parent::setUp();
        $this->out = sys_get_temp_dir().DIRECTORY_SEPARATOR.'amir-zone-export-'.uniqid();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->out.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->out);
        parent::tearDown();
    }

    /** @return array<int, string> */
    private function export(int $maxBytes = 300): array
    {
        return app(ZoneExporter::class)->export(base_path('tests/Fixtures/zone'), $this->out, $maxBytes)['files'];
    }

    private function decode(string $file): array
    {
        return json_decode((string) gzdecode((string) file_get_contents($file)), true);
    }

    public function test_the_export_is_split_into_numbered_pieces_without_padel(): void
    {
        // un vecchio pezzo nella cartella non deve restare a confondere l'ordine
        mkdir($this->out, 0775, true);
        file_put_contents($this->out.'/zone-099-reports.json.gz', gzencode('{}'));

        $files = $this->export(300);

        $names = array_map('basename', $files);
        $this->assertNotContains('zone-099-reports.json.gz', $names);
        foreach ($names as $i => $name) {
            $this->assertMatchesRegularExpression(sprintf('/^zone-%03d-(tournaments|clubs|teams|players|calendar|tables|reports)\.json\.gz$/', $i + 1), $name);
        }
        $sections = array_values(array_unique(array_map(fn ($n) => preg_replace('/^zone-\d{3}-(\w+)\.json\.gz$/', '$1', $n), $names)));
        $this->assertSame(ZoneExporter::SECTIONS, $sections, 'le sezioni escono nell\'ordine di import');
        $this->assertGreaterThan(count(ZoneExporter::SECTIONS), count($files), 'con un limite piccolissimo le sezioni si spezzano in più pezzi');

        $tournamentIds = [];
        foreach ($files as $f) {
            $piece = $this->decode($f);
            $this->assertSame(preg_replace('/^zone-\d{3}-(\w+)\.json\.gz$/', '$1', basename($f)), $piece['section']);
            if ($piece['section'] === 'tournaments') {
                $tournamentIds = array_merge($tournamentIds, array_column($piece['items'], 'id'));
            }
        }
        $this->assertSame([901], $tournamentIds, 'il padel (902) e l\'id inesistente (903) restano fuori');

        // con il limite di serie ogni sezione sta in un pezzo solo
        $this->assertCount(7, $this->export(3_500_000));
    }

    public function test_the_pieces_are_uploaded_in_order_and_the_status_counts_what_arrived(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $files = $this->export(300);

        foreach ($files as $file) {
            $piece = $this->decode($file);
            $r = $this->post('/api/v1/zone/import', ['file' => new UploadedFile($file, basename($file), 'application/gzip', null, true)], ['Accept' => 'application/json'])
                ->assertOk()->json('data');
            $this->assertSame($piece['section'], $r['section']);
            $this->assertSame(count($piece['items']), $r['items']);
            $this->assertIsArray($r['counts']);
        }

        $status = $this->getJson('/api/v1/zone/status')->assertOk()->json('data');
        $this->assertSame(['tournaments' => 1, 'clubs' => 3, 'teams' => 2, 'players' => 1, 'matches' => 3, 'reports' => 2], collect($status)->except('sections')->all());
        $this->assertEqualsCanonicalizing(ZoneExporter::SECTIONS, collect($status['sections'])->pluck('section')->all(), 'ogni sezione ha il suo stato');
        $reports = collect($status['sections'])->firstWhere('section', 'reports');
        $this->assertNotNull($reports['synced_at']);
        $this->assertSame(1, $reports['counts']['items'], 'con il limite piccolo ogni pezzo dei referti ha un referto solo');
        $this->assertNotNull(XfTournament::find(901)->calendar_synced_at);
        $this->assertTrue(XfMatch::find(90001)->has_report);
        // l'ordine dei pezzi (squadre prima dei club) fa sì che rose e referti arrivino già abbinati ai profili
        $this->assertSame(7001, XfTeamPlayer::where('tpid', 61001)->first()->player_id);
        $this->assertSame(7001, XfMatchPlayer::where('match_id', 90001)->where('tpid', 61001)->first()->player_id);
        $this->assertSame(7002, XfMatchPlayer::where('match_id', 90002)->where('tpid', 61002)->first()->player_id);

        // ricaricare lo stesso pezzo non duplica nulla
        $this->post('/api/v1/zone/import', ['file' => new UploadedFile($files[0], basename($files[0]), 'application/gzip', null, true)], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(1, XfTournament::count());
        $this->assertSame(1, XfSyncState::where('section', 'tournaments')->count());
    }

    public function test_the_upload_needs_the_staff_login_and_a_real_piece(): void
    {
        $files = $this->export(300);
        $this->post('/api/v1/zone/import', ['file' => new UploadedFile($files[0], basename($files[0]), 'application/gzip', null, true)], ['Accept' => 'application/json'])->assertUnauthorized();
        $this->getJson('/api/v1/zone/status')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());
        $this->post('/api/v1/zone/import', ['file' => UploadedFile::fake()->createWithContent('x.json.gz', 'non sono gzip')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->post('/api/v1/zone/import', ['file' => UploadedFile::fake()->createWithContent('x.json.gz', gzencode('{"section":"boh","items":[]}'))], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->post('/api/v1/zone/import', [], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertSame(0, XfTournament::count());
    }
}
