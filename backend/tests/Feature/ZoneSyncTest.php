<?php

namespace Tests\Feature;

use App\Models\SyncRun;
use App\Models\User;
use App\Models\Zone\XfMatch;
use App\Models\Zone\XfMatchPlayer;
use App\Models\Zone\XfPlayer;
use App\Models\Zone\XfPlayerStat;
use App\Models\Zone\XfSeason;
use App\Models\Zone\XfStanding;
use App\Models\Zone\XfSyncState;
use App\Models\Zone\XfTeam;
use App\Models\Zone\XfTeamPlayer;
use App\Models\Zone\XfTournament;
use App\Services\Xfive\Archive\Limiter;
use App\Services\Zone\ZoneImporter;
use App\Services\Zone\ZoneSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Gli aggiornamenti dal vivo della Mixed Zone (ZoneSync): a pezzi entro una scadenza, con il cursore in xf_sync_state,
 * su pagine XFive finte costruite come quelle vere.
 */
class ZoneSyncTest extends TestCase
{
    use RefreshDatabase;

    /** In quale elenco (ongoing/incoming/previous) XFive mette i tornei finti. */
    private string $listStatus = 'ongoing';

    protected function setUp(): void
    {
        parent::setUp();
        config(['amir.xfive.throttle_ms' => 0, 'amir.sync.inline' => true, 'amir.sync.budget' => 40, 'amir.xfive.seasons' => [8 => '2026/2027']]);
        // il freno c'è ma nei test non dorme
        $this->app->when(ZoneSync::class)->needs(Limiter::class)->give(fn () => new Limiter(gap: 1.0, sleep: fn () => null));
    }

    private function zoneFixture(string $rel): array
    {
        return json_decode((string) file_get_contents(base_path("tests/Fixtures/zone/{$rel}")), true);
    }

    private function sync(): ZoneSync
    {
        return app(ZoneSync::class);
    }

    private function far(): float
    {
        return microtime(true) + 40;
    }

    /** @return array<int, array{0:string,1:int,2:int}> i messaggi di avanzamento raccolti */
    private function progress(array &$log): \Closure
    {
        return function (string $message, int $done, int $total) use (&$log): void {
            $log[] = [$message, $done, $total];
        };
    }

    private function tournament(int $id, string $name = 'CITTADELLA', string $status = 'ongoing'): XfTournament
    {
        XfSeason::firstOrCreate(['id' => 8], ['label' => '2026/2027']);

        return XfTournament::create(['id' => $id, 'season_id' => 8, 'name' => $name, 'slug' => strtolower($name), 'sport' => 'Calcio a 8 - Maschile', 'format' => 8, 'status' => $status]);
    }

    // ------------------------------------------------------------------ pagine XFive finte

    private function tournamentListHtml(): string
    {
        return <<<'HTML'
        <a href='https://www.xfivesport.it/it/tournament/901/torneo-di-prova/stream/'><div class="event-tournament-list-box">
          <div class='data'><div class='name'>TORNEO DI PROVA</div><div class='info'>Calcio a 7 | 3 Squadre</div><div class='date'>Dal 12 ottobre</div></div></div></a>
        <a href='https://www.xfivesport.it/it/tournament/902/padel-cup/stream/'><div class="event-tournament-list-box">
          <div class='data'><div class='name'>PADEL CUP</div><div class='info'>Padel doppio | 8 Squadre</div><div class='date'>Dal 1 ottobre</div></div></div></a>
        HTML;
    }

    private function tournamentHeaderHtml(): string
    {
        return <<<'HTML'
        <div class="tournament-flyer"><img src="https://cdn.enjore.com/wl/xfivesport_it/img/tournament/b/901prova.jpg" /></div>
        <div class="tournament-title wl-font-header">TORNEO DI PROVA</div>
        <div class="season-title"><a href="https://www.xfivesport.it/it/league/1/xfive/season/8/20262027/tournament-list/">Stagione 2026/2027</a></div>
        <div class="tournament-sport">Calcio a 7 - Maschile</div>
        HTML;
    }

    /** Il calendario stampabile di un torneo: tre partite (due giocate, una no), id = torneo x 100 + n. */
    private function printableHtml(int $tid = 901): string
    {
        $match = function (int $mid, int $home, int $away, string $homeName, string $awayName, ?int $hs, ?int $as, string $when) use ($tid): string {
            $played = $hs !== null ? 'played' : '';

            return <<<HTML
            <div id="match-{$mid}" class="tcalendar-match-container {$played} round-9901 team-{$home} team-{$away} tourn-{$tid} field-2">
              <div class='tmatch-top wl-theme-color'> GIORNATA 1</div>
              <div class='tmatch-middle'><div class='tmatch-left'>
                <div class='top'><div class="team-img"><img src='https://cdn.enjore.com/b/{$home}.png'></div><div class="team-name ">{$homeName}</div></div>
                <div class='bottom'><div class="team-img"><img src='https://cdn.enjore.com/b/{$away}.png'></div><div class="team-name ">{$awayName}</div></div>
              </div><div class='tmatch-right'><div class='top '>{$hs}</div><div class='bottom '>{$as}</div></div></div>
              <div class='tmatch-bottom'><div class='left'><b>prova</b></div><div class='center'>{$when}</div><div class='right'>CAMPO DI PROVA 1</div></div>
            </div>
            HTML;
        };

        return '<html><body><div id="tcalendar-container">'
            .$match($tid * 100 + 1, 301, 302, 'caffè km0', 'rossi', 3, 1, 'lun 12/10 21:00')
            .$match($tid * 100 + 2, 303, 301, 'bianchi', 'caffè km0', 2, 2, 'mar 13/10 21:00')
            .$match($tid * 100 + 3, 302, 303, 'rossi', 'bianchi', null, null, 'lun 19/10 21:00')
            .'</div></body></html>';
    }

    private function standingsHtml(): string
    {
        return <<<'HTML'
        <h4 class="section-head">Girone A</h4>
        <div class="tables-container">
          <div class="left-table">
            <div class="tables-header tables-row"><div class='col-pos tables-pos'>#</div><div class='col-name tables-main'>Squadra</div></div>
            <div class="tables-body tables-row even"><div class="col-pos tables-pos"><small>1</small></div><div class='col-name tables-main'><img src="https://cdn.enjore.com/b/301.png"> <div class="participant-name">CAFFÈ KM0</div></div></div>
            <div class="tables-body tables-row odd"><div class="col-pos tables-pos"><small>2</small></div><div class='col-name tables-main'><img src="https://cdn.enjore.com/b/302.png"> <div class="participant-name">ROSSI</div></div></div>
          </div>
          <div class="right-table"><div>
            <div class='tables-header tables-row'><div class='col-data' title="Punti Classifica">Pt</div><div class='col-data' title="Partite Disputate">G</div></div>
            <div class="tables-body tables-row even"><div class="col-data"><small>4</small></div><div class="col-data"><small>2</small></div></div>
            <div class="tables-body tables-row odd"><div class="col-data"><small>0</small></div><div class="col-data"><small>1</small></div></div>
          </div></div>
        </div>
        HTML;
    }

    private function statsHtml(string $type): string
    {
        $rows = match ($type) {
            'score' => [['Verdi L.', 'CAFFÈ KM0', [2]]],
            'top-player' => [['Verdi L.', 'CAFFÈ KM0', [1]]],
            default => [['Neri M.', 'CAFFÈ KM0', [1, 0]]],
        };
        $left = '<div class="left-table"><div class="tables-header tables-row"><div class="col-pos">#</div><div class="col-name">Giocatore</div></div>';
        $right = '<div class="right-table"><div><div class="tables-header tables-row"><div class="col-data" title="Goal">G</div>'.($type === 'discipline' ? '<div class="col-data" title="Espulsioni">E</div>' : '').'</div>';
        foreach ($rows as $i => [$name, $team, $values]) {
            $left .= '<div class="tables-body tables-row"><div class="col-pos tables-pos"><small>'.($i + 1).'</small></div><div class="col-name tables-main"><img src="https://cdn.enjore.com/wl/x/img/player/q/7001-verdi.png"><div class="participant-name">'.$name.'<small>'.$team.'</small></div></div></div>';
            $right .= '<div class="tables-body tables-row">'.implode('', array_map(fn ($v) => '<div class="col-data"><small>'.$v.'</small></div>', $values)).'</div>';
        }

        return '<div class="tables-container">'.$left.'</div>'.$right.'</div></div></div>';
    }

    private function teamListHtml(): string
    {
        return '<div class="t-participants-container"><a class="participant-element" href="https://www.xfivesport.it/it/team/5001/caffe-km0/"><div class="participant-single-container"><img class="img-circle" src="https://cdn.enjore.com/b/301.png"><div class="participant-detail"><div>CAFFÈ KM0</div></div></div></a></div>';
    }

    private function teamPageHtml(): string
    {
        return <<<'HTML'
        <div class="team-header"><img src="https://cdn.enjore.com/wl/xfivesport_it/img/team/badge/s/301caffe.png"/><div class="team-name wl-font-header"><h3 class="wl-font-header">CAFFÈ KM0</h3></div></div>
        <div class="col-xs-24 team-staff-container"><div class="info">Presidente: Anna Verdi</div><a id="linkToHistory" href="https://www.xfivesport.it/it/team-h/301/caffe-km0/">Storico Squadra</a></div>
        <div id="team-roster-container">
          <div class="col-xs-24 col-sm-12"><a href="https://www.xfivesport.it/it/player/61001/luca-verdi/"><div class="player-container"><img class="round-img" src="https://cdn.enjore.com/wl/xfivesport_it/img/player/q/7001-verdi.png" />
            <div class="player-info"><span class="player-name">Verdi Luca</span><br /><span class="player-role">Attaccante</span><br /><img class="player-country-img" title="Italia" src="/IT.gif"><div class="player-number"> 9 </div></div></div></a></div>
        </div>
        HTML;
    }

    private function clubPageHtml(): string
    {
        return '<h3>CAFFÈ KM0</h3><a href="https://www.xfivesport.it/it/player-info/7001/luca-verdi/">Verdi Luca 9</a>';
    }

    private function playerInfoHtml(int $age = 30, string $nationality = 'Italia'): string
    {
        return '<html><body><div class="wl-playername"><h3>Verdi Luca</h3></div><img src="https://cdn.enjore.com/wl/xfivesport_it/img/player/q/7001-verdi.png" />'
            .'<dl class="dl-horizontal"><dt>Età:</dt><dd>'.$age.'</dd><dt>Nazionalità:</dt><dd>'.$nationality.'</dd></dl>'
            .'<div id="wl-playedtournament-container"><div class="wl-tournamentinfo-container"><h4><a href="https://www.xfivesport.it/it/team-h/301/club/">Caffè Km0</a></h4><span></span></div>'
            .'<div class="wl-tournamentinfo-container"><h4><a href="https://www.xfivesport.it/it/tournament/901/t/">Torneo Di Prova</a></h4><span>2026/2027 - Calcio a 7</span></div></div></body></html>';
    }

    /** Tutte le pagine finte insieme; $printableDelay rallenta il calendario per far scadere il tempo in modo prevedibile. */
    private function fakePages(float $printableDelay = 0.0): void
    {
        Http::fake([
            '*/league.php' => function (Request $request) {
                return match ((int) $request['op']) {
                    23 => Http::response(['html' => $request['status'] === $this->listStatus ? $this->tournamentListHtml() : '<div>Non ci sono ancora dati</div>']),
                    20 => Http::response(['html' => $this->standingsHtml()]),
                    19 => Http::response(['html' => $this->statsHtml((string) $request['type'])]),
                    21 => Http::response(['html' => $this->teamListHtml()]),
                    default => Http::response(['html' => '']),
                };
            },
            '*/t-printable.php*' => function (Request $request) use ($printableDelay) {
                if ($printableDelay > 0) {
                    usleep((int) ($printableDelay * 1_000_000));
                }
                preg_match('/[?&]t=(\d+)/', $request->url(), $m);

                return Http::response($this->printableHtml((int) ($m[1] ?? 901)));
            },
            '*/it/tournament/*' => Http::response($this->tournamentHeaderHtml()),
            '*/it/match/*' => Http::response((string) file_get_contents(base_path('tests/Fixtures/xfive/match-with-referee-and-mvp.html'))),
            '*/it/team-h/*' => Http::response($this->clubPageHtml()),
            '*/it/team/*' => Http::response($this->teamPageHtml()),
            '*/it/player-info/*' => Http::response($this->playerInfoHtml()),
        ]);
    }

    // ------------------------------------------------------------------ tornei

    public function test_the_tournaments_section_imports_football_and_never_asks_for_padel(): void
    {
        $this->fakePages();
        $log = [];

        $r = $this->sync()->tournaments($this->far(), $this->progress($log));

        $this->assertSame([901], XfTournament::pluck('id')->all(), 'il padel non entra');
        $this->assertEquals(['tournaments' => 1, 'lists' => 3, 'requests' => 4, 'errors' => 0, 'remaining' => 0], array_intersect_key($r, array_flip(['tournaments', 'lists', 'requests', 'errors', 'remaining'])));
        Http::assertSentCount(4); // tre elenchi e una sola intestazione: del padel non si chiede la pagina
        $t = XfTournament::find(901);
        $this->assertSame(['Calcio a 7 - Maschile', 7, 'ongoing', 3, 'Dal 12 ottobre', 'torneo-di-prova'], [$t->sport, $t->format, $t->status, $t->teams_count, $t->dates, $t->slug]);
        $this->assertSame('2026/2027', XfSeason::find(8)->label);
        $this->assertStringContainsString('Leggo l\'elenco dei tornei 2026/27 in corso (1 di 3)', $log[0][0]);
        $this->assertSame('Leggo il torneo TORNEO DI PROVA (2026/27) (1 di 1)', end($log)[0]);

        $state = XfSyncState::find('zone-tournaments');
        $this->assertNotNull($state->synced_at);
        $this->assertNull($state->cursor, 'giro finito: niente cursore');
        $this->assertSame(1, $state->counts['tournaments']);

        // la notte dopo: solo gli elenchi, il torneo c'è già; se XFive lo dichiara concluso lo stato cambia
        $this->listStatus = 'previous';
        $r = $this->sync()->tournaments($this->far(), $this->progress($log));
        Http::assertSentCount(7);
        $this->assertSame('previous', XfTournament::find(901)->status);
        $this->assertSame(1, $r['updated']);
        $this->assertArrayNotHasKey('tournaments', $r);
    }

    // ------------------------------------------------------------------ calendari e cursore

    public function test_the_calendar_stops_at_the_deadline_and_resumes_from_the_cursor_without_rereading(): void
    {
        $this->tournament(901, 'TORNEO DI PROVA');
        $this->tournament(905, 'COPPA DI PROVA');
        $this->tournament(906, 'VECCHIO', 'previous'); // non attivo: non si legge
        $this->fakePages(printableDelay: 0.3);
        $log = [];

        // senza tempo: nulla letto, ma il giro è iniziato e dice quanto resta
        $r = $this->sync()->calendar(microtime(true), $this->progress($log));
        $this->assertSame(2, $r['remaining']);
        Http::assertNothingSent();
        $this->assertNotNull(XfSyncState::find('zone-calendar')->cursor);
        $this->assertSame([], XfSyncState::find('zone-calendar')->cursor['done']);

        // tempo per un torneo solo: il calendario dura 0,3 s, la scadenza arriva prima del secondo
        $r = $this->sync()->calendar(microtime(true) + 0.1, $this->progress($log));
        $this->assertSame(1, $r['remaining']);
        $this->assertSame(3, $r['matches']);
        Http::assertSentCount(1);
        $this->assertSame([901], XfSyncState::find('zone-calendar')->cursor['done']);
        $this->assertNotNull(XfTournament::find(901)->calendar_synced_at);
        $this->assertNull(XfTournament::find(905)->calendar_synced_at);
        $this->assertSame('Leggo il calendario di TORNEO DI PROVA 2026/27 (1 di 2)', $log[0][0]);

        // il pezzo dopo riprende dal cursore: 905 sì, 901 no (Review Focus 5)
        $r = $this->sync()->calendar($this->far(), $this->progress($log));
        $this->assertSame(0, $r['remaining']);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $req) => str_contains($req->url(), 't=905'));
        $this->assertSame('Leggo il calendario di COPPA DI PROVA 2026/27 (2 di 2)', end($log)[0]);
        $state = XfSyncState::find('zone-calendar');
        $this->assertNull($state->cursor);
        $this->assertNotNull($state->synced_at);
        $this->assertEquals(['tournaments' => 2, 'matches' => 6, 'clubs' => 6], array_intersect_key($state->counts, array_flip(['tournaments', 'matches', 'clubs'])));
        $this->assertSame(6, XfMatch::count());
        $this->assertSame('2026-10-12 21:00', XfMatch::find(90101)->kickoff_at->setTimezone('Europe/Rome')->format('Y-m-d H:i'));
        $this->assertSame(905, XfMatch::find(90501)->tournament_id);

        // un giro nuovo riparte da chi è stato letto per primo (il più vecchio)
        $r = $this->sync()->calendar($this->far(), $this->progress($log));
        Http::assertSentCount(4);
        $this->assertSame(0, $r['remaining']);
    }

    // ------------------------------------------------------------------ classifiche e statistiche

    public function test_standings_and_stats_sections_fill_the_tables_of_active_tournaments(): void
    {
        $this->tournament(901, 'TORNEO DI PROVA');
        app(ZoneImporter::class)->calendar(901, $this->zoneFixture('tournaments/901/calendar.json'), '2026/2027'); // i club, per abbinare la classifica
        $this->fakePages();
        $log = [];

        $r = $this->sync()->standings($this->far(), $this->progress($log));
        $this->assertEquals(['tournaments' => 1, 'standings' => 2, 'groups' => 1, 'remaining' => 0], array_intersect_key($r, array_flip(['tournaments', 'standings', 'groups', 'remaining'])));
        $row = XfStanding::where('tournament_id', 901)->where('position', 1)->first();
        $this->assertSame(['Girone A', 'CAFFÈ KM0', 301, ['Pt' => 4, 'G' => 2]], [$row->group, $row->name, $row->club_id, $row->values]);
        $this->assertNotNull(XfTournament::find(901)->standings_synced_at);

        $r = $this->sync()->stats($this->far(), $this->progress($log));
        $this->assertEquals(['tournaments' => 1, 'stats' => 3, 'requests' => 3, 'remaining' => 0], array_intersect_key($r, array_flip(['tournaments', 'stats', 'requests', 'remaining'])));
        $this->assertSame(['score' => 1, 'top-player' => 1, 'discipline' => 1], XfPlayerStat::where('tournament_id', 901)->get()->countBy('type')->all());
        $this->assertSame([1, 0], XfPlayerStat::where('type', 'discipline')->first()->values);
        $this->assertNotNull(XfTournament::find(901)->stats_synced_at);
        $this->assertSame('Leggo le statistiche di TORNEO DI PROVA 2026/27 (1 di 1)', end($log)[0]);
        Http::assertSentCount(4);
    }

    // ------------------------------------------------------------------ squadre, rose, club

    public function test_the_teams_section_reads_rosters_and_the_club_page_links_them_to_global_profiles(): void
    {
        $this->tournament(901, 'TORNEO DI PROVA');
        $this->fakePages();
        $log = [];

        $r = $this->sync()->teams($this->far(), $this->progress($log));

        $this->assertEquals(['tournaments' => 1, 'teams' => 1, 'roster' => 1, 'clubs' => 1, 'linked' => 1, 'requests' => 3, 'remaining' => 0], array_intersect_key($r, array_flip(['tournaments', 'teams', 'roster', 'clubs', 'linked', 'requests', 'remaining'])));
        $team = XfTeam::find(5001);
        $this->assertSame([901, 301, 'CAFFÈ KM0', ['Presidente' => 'Anna Verdi']], [$team->tournament_id, $team->club_id, $team->name, $team->staff]);
        $this->assertSame(7001, XfTeamPlayer::where('tpid', 61001)->first()->player_id, 'la pagina del club abbina la rosa al profilo globale');
        $this->assertNotNull(XfTournament::find(901)->teams_synced_at);
        $this->assertSame('Leggo le squadre di TORNEO DI PROVA 2026/27 (1 di 1)', $log[0][0]);
        $this->assertSame('Leggo la rosa di CAFFÈ KM0, TORNEO DI PROVA 2026/27 (1 di 1)', $log[1][0]);
        $this->assertNull(XfSyncState::find('zone-teams')->cursor);

        // la settimana dopo la rosa è già abbinata: la pagina del club non si richiede
        $r = $this->sync()->teams($this->far(), $this->progress($log));
        $this->assertSame(2, $r['requests']);
        Http::assertSentCount(5);
    }

    // ------------------------------------------------------------------ referti

    public function test_the_reports_section_saves_the_lineups_and_marks_the_match(): void
    {
        $this->tournament(901, 'TORNEO DI PROVA');
        app(ZoneImporter::class)->calendar(901, $this->zoneFixture('tournaments/901/calendar.json'), '2026/2027');
        $this->fakePages();
        $log = [];

        $r = $this->sync()->reports($this->far(), $this->progress($log));

        $this->assertEquals(['reports' => 2, 'requests' => 2, 'remaining' => 0], array_intersect_key($r, array_flip(['reports', 'requests', 'remaining'])));
        $m = XfMatch::find(90001);
        $this->assertTrue($m->has_report);
        $this->assertSame('Arbitro Esempio', $m->referee);
        $this->assertGreaterThan(10, XfMatchPlayer::where('match_id', 90001)->count());
        $this->assertFalse(XfMatch::find(90003)->has_report, 'una partita non giocata non ha referto');
        $this->assertStringStartsWith('Leggo il referto di ', $log[0][0]);
        $this->assertStringContainsString('TORNEO DI PROVA 2026/27 (1 di 2)', $log[0][0]);

        // la notte dopo non c'è più nulla da leggere
        $r = $this->sync()->reports($this->far(), $this->progress($log));
        $this->assertSame(0, $r['requests']);
        Http::assertSentCount(2);
    }

    // ------------------------------------------------------------------ profili

    public function test_the_players_section_reads_missing_and_stale_profiles_only(): void
    {
        $this->tournament(901, 'TORNEO DI PROVA');
        XfTeam::create(['id' => 5001, 'tournament_id' => 901, 'club_id' => 301, 'name' => 'CAFFÈ KM0']);
        XfTeamPlayer::create(['team_id' => 5001, 'tpid' => 61001, 'player_id' => 7001, 'name' => 'Verdi Luca', 'slug' => 'luca-verdi']); // profilo mai letto
        XfPlayer::create(['id' => 7002, 'name' => 'Neri Marco', 'synced_at' => now()->subDays(40)]); // vecchio
        XfPlayer::create(['id' => 7003, 'name' => 'Bianchi Gino', 'synced_at' => now()->subDay()]); // fresco
        $this->fakePages();
        $log = [];

        $r = $this->sync()->players($this->far(), $this->progress($log));

        $this->assertEquals(['players' => 2, 'requests' => 2, 'remaining' => 0], array_intersect_key($r, array_flip(['players', 'requests', 'remaining'])));
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $req) => str_contains($req->url(), '/player-info/7001/'));
        Http::assertSent(fn (Request $req) => str_contains($req->url(), '/player-info/7002/'));
        $p = XfPlayer::find(7001);
        $this->assertSame(['Verdi Luca', 'luca-verdi', 30, 'Italia'], [$p->name, $p->slug, $p->age, $p->nationality]);
        $this->assertStringContainsString('7001-verdi.png', $p->photo_url);
        $this->assertSame(901, $p->profile[0]['tournaments'][0]['id']);
        $this->assertSame('Neri Marco', XfPlayer::find(7002)->name, 'il nome resta quello di prima');
        $this->assertTrue(XfPlayer::find(7002)->synced_at->greaterThan(now()->subMinute()));
        $this->assertSame('Leggo il profilo di Verdi Luca (1 di 2)', $log[0][0]);
    }

    // ------------------------------------------------------------------ dal sito e dal cron

    public function test_the_sync_endpoint_answers_with_progress_and_the_cron_runs_the_whole_zone_chain(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->tournament(901, 'TORNEO DI PROVA');
        $this->fakePages();

        $this->postJson('/api/v1/sync/xfive', ['scope' => 'zone-calendar'])
            ->assertOk()
            ->assertJsonPath('data.scope', 'zone-calendar')
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.stats.matches', 3)
            ->assertJsonPath('data.stats.remaining', 0)
            ->assertJsonPath('data.progress.section', 'zone-calendar')
            ->assertJsonPath('data.progress.message', 'Leggo il calendario di TORNEO DI PROVA 2026/27 (1 di 1)')
            ->assertJsonPath('data.progress.total', 1);
        $this->getJson('/api/v1/sync/runs')->assertOk()->assertJsonPath('data.0.progress.section', 'zone-calendar');

        // il cron «zone»: di martedì le sezioni di ogni notte, di lunedì anche squadre e profili
        config(['amir.cron_secret' => 's3greto-di-prova']);
        Carbon::setTestNow(Carbon::parse('2026-10-13 03:30', 'Europe/Rome')); // martedì
        $this->getJson('/api/v1/cron/zone', ['Authorization' => 'Bearer s3greto-di-prova'])
            ->assertOk()
            ->assertJsonPath('data.scope', 'zone')
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.stats.sections', 5)
            ->assertJsonPath('data.stats.remaining', 0);
        $this->assertNull(XfSyncState::find('zone-teams'));
        $this->assertTrue(XfMatch::find(90101)->refresh()->has_report, 'i referti arrivano nel giro notturno');

        Carbon::setTestNow(Carbon::parse('2026-10-12 03:30', 'Europe/Rome')); // lunedì
        $this->getJson('/api/v1/cron/zone', ['Authorization' => 'Bearer s3greto-di-prova'])
            ->assertOk()
            ->assertJsonPath('data.stats.sections', 7);
        $this->assertNotNull(XfSyncState::find('zone-teams')?->synced_at);
        $this->assertNotNull(XfSyncState::find('zone-players')?->synced_at);
        $this->assertSame(7001, XfTeamPlayer::where('tpid', 61001)->first()->player_id);
        Carbon::setTestNow();

        $this->assertSame(3, SyncRun::count());
        $this->assertSame('zone', SyncRun::latest('id')->first()->scope);
    }

    public function test_a_zone_update_without_time_left_says_how_much_is_left_without_asking_xfive(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->tournament(901, 'TORNEO DI PROVA');
        config(['amir.sync.budget' => 0]);
        $this->fakePages();

        $this->postJson('/api/v1/sync/xfive', ['scope' => 'zone'])
            ->assertOk()
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.stats.sections', 1) // i referti: coda vuota, quindi fatti
            ->assertJsonPath('data.stats.remaining', 6); // 3 elenchi + calendario, classifica e statistiche del torneo
        $this->assertSame(6, $this->postJson('/api/v1/sync/xfive', ['scope' => 'zone'])->json('data.stats.remaining'));
        Http::assertNothingSent();
    }
}
