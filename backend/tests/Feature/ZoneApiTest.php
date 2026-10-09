<?php

namespace Tests\Feature;

use App\Models\Zone\XfClub;
use App\Models\Zone\XfPlayer;
use App\Models\Zone\XfTournament;
use App\Services\Zone\ZoneImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * L'API pubblica della Mixed Zone (/api/v1/public/zone/...): le fixture di tests/Fixtures/zone importate in setUp,
 * un caso per indirizzo con la forma del contratto frontend/src/api/zone-types.ts.
 */
class ZoneApiTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/public/zone';

    protected function setUp(): void
    {
        parent::setUp();
        // il 10 ottobre 2026: due partite giocate (12 e 13 ottobre sono «passate» per la stagione) e una in arrivo il 19
        Carbon::setTestNow('2026-10-10 12:00:00');

        $imp = app(ZoneImporter::class);
        $imp->tournament($this->zoneFixture('tournaments/901/tournament.json'));
        $imp->team($this->zoneFixture('teams/5001.json'));
        $imp->team($this->zoneFixture('teams/5002.json'));
        $imp->team(['id' => 5003, 'tournament_id' => 901, 'slug' => 'bianchi', 'name' => 'BIANCHI', 'club_id' => 303, 'players' => [['id' => 61004, 'slug' => 'pino-gialli', 'name' => 'Gialli Pino']]]);
        $imp->club($this->zoneFixture('clubs/301.json'));
        $imp->player($this->zoneFixture('players/7001.json'));
        $imp->player(['id' => 7002, 'name' => 'Neri Marco 7', 'slug' => 'marco-neri', 'age' => 25, 'nationality' => 'Italia', 'clubs' => []]);
        $imp->calendar(901, $this->zoneFixture('tournaments/901/calendar.json'), '2026/2027');
        $imp->standings(901, $this->zoneFixture('tournaments/901/standings.json'));
        $imp->playerStats(901, $this->zoneFixture('tournaments/901/player-stats.json'));
        $imp->documents(901, $this->zoneFixture('tournaments/901/docs.json'));
        $imp->report($this->zoneFixture('matches/90001.json'));
        $imp->report($this->zoneFixture('matches/90002.json'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function zoneFixture(string $rel): array
    {
        return json_decode((string) file_get_contents(base_path("tests/Fixtures/zone/{$rel}")), true);
    }

    private function data(string $path): array
    {
        return $this->getJson(self::BASE.$path)->assertOk()->json('data');
    }

    public function test_the_search_column_is_filled_by_the_importer(): void
    {
        $this->assertSame('caffe km0', XfClub::find(301)->search);
        $this->assertSame('verdi luca', XfPlayer::find(7001)->search);
        $this->assertSame('torneo di prova', XfTournament::find(901)->search);
    }

    public function test_home_has_seasons_results_upcoming_tournaments_by_sport_and_totals(): void
    {
        $r = $this->getJson(self::BASE.'/home')->assertOk();
        $this->assertStringContainsString('s-maxage=60', (string) $r->headers->get('Cache-Control'), 'la cache pubblica copre la Mixed Zone');
        $home = $r->json('data');

        $this->assertSame([['id' => 8, 'label' => '2026/2027', 'tournaments' => 1]], $home['seasons']);
        $this->assertSame('2026/2027', $home['season']);
        $this->assertSame(['tournaments' => 1, 'clubs' => 3, 'players' => 2, 'matches' => 3, 'reports' => 2], $home['totals']);

        $this->assertSame([90002, 90001], array_column($home['latest'], 'id'), 'dal risultato più recente');
        $this->assertSame([90003], array_column($home['upcoming'], 'id'));
        $m = $home['latest'][1];
        $this->assertSame(['id', 'tournament', 'round', 'round_label', 'home', 'away', 'kickoff_at', 'kickoff_raw', 'venue', 'home_score', 'away_score', 'played', 'has_report'], array_keys($m));
        $this->assertSame(['id' => 901, 'name' => 'Torneo di prova', 'season' => '2026/2027', 'sport' => 'Calcio a 7 - Maschile', 'format' => 7], $m['tournament']);
        $this->assertSame(['id' => 301, 'name' => 'CAFFÈ KM0', 'badge_url' => 'https://cdn.enjore.com/wl/xfivesport_it/img/team/badge/q/301caffe.png'], $m['home'], 'lo stemma è quello della pagina club, letta dopo il calendario');
        $this->assertSame('2026-10-12T21:00:00+02:00', $m['kickoff_at'], 'ISO 8601 con il fuso');
        $this->assertSame([3, 1, true, true], [$m['home_score'], $m['away_score'], $m['played'], $m['has_report']]);

        $this->assertCount(1, $home['tournaments']);
        $this->assertSame('Calcio a 7 - Maschile', $home['tournaments'][0]['sport']);
        $t = $home['tournaments'][0]['items'][0];
        $this->assertEquals(['id' => 901, 'status' => 'ongoing', 'played' => 2, 'total' => 3, 'season_id' => 8, 'slug' => 'torneo-di-prova', 'teams_count' => 3], collect($t)->only('id', 'status', 'played', 'total', 'season_id', 'slug', 'teams_count')->all());

        // una stagione che non c'è: si torna a quella di serie, così la pagina non resta vuota
        $this->assertSame('2026/2027', $this->data('/home?season=1999/2000')['season']);
    }

    public function test_search_ignores_accents_and_case(): void
    {
        $r = $this->data('/search?q=caffe');
        $this->assertSame(['CAFFÈ KM0'], array_column($r['clubs'], 'name'));
        $this->assertSame([], $r['players']);

        $r = $this->data('/search?q=VERDI');
        $this->assertSame([['id' => 7001, 'name' => 'Verdi Luca', 'photo_url' => 'https://cdn.enjore.com/wl/xfivesport_it/img/player/q/7001-verdi.png']], $r['players']);
        $this->assertSame([901], array_column($this->data('/search?q=prova')['tournaments'], 'id'));
        $this->assertSame(['clubs' => [], 'players' => [], 'tournaments' => []], $this->data('/search?q=x'), 'sotto le due lettere non si cerca');
    }

    public function test_tournaments_can_be_filtered_by_season_and_sport(): void
    {
        $this->assertSame([901], array_column($this->data('/tournaments'), 'id'));
        $this->assertSame([901], array_column($this->data('/tournaments?season=2026/2027&sport=Calcio+a+7+-+Maschile'), 'id'));
        $this->assertSame([], $this->data('/tournaments?season=2025/2026'));
        $this->assertSame([], $this->data('/tournaments?sport=Calcio+a+8+-+Maschile'));
    }

    public function test_the_tournament_page_has_groups_teams_documents_and_matches(): void
    {
        $page = $this->data('/tournaments/901');
        $this->assertSame(['tournament', 'standings', 'teams', 'documents', 'latest', 'upcoming'], array_keys($page));
        $this->assertSame(901, $page['tournament']['id']);

        $this->assertSame(['Girone A', 'Girone B'], array_column($page['standings'], 'group'), 'i gironi restano separati');
        $a = $page['standings'][0];
        $this->assertSame(['key' => 'Pt', 'label' => 'Punti'], $a['columns'][0]);
        $this->assertSame(['key' => '+/-', 'label' => 'Differenza reti'], $a['columns'][7]);
        $this->assertSame(['Pt' => 4, 'G' => 2, 'V' => 1, 'N' => 1, 'P' => 0, 'F' => 5, 'S' => 3, '+/-' => 2, 'FP' => 0], $a['rows'][0]['values']);
        $this->assertSame(['id' => 301, 'name' => 'CAFFÈ KM0'], collect($a['rows'][0]['club'])->only('id', 'name')->all());
        $this->assertSame(['W', 'D'], $a['rows'][0]['form'], 'dal più vecchio al più recente');
        $this->assertSame(['L'], $a['rows'][1]['form']);

        $this->assertSame(['BIANCHI', 'CAFFÈ KM0', 'ROSSI'], array_column($page['teams'], 'name'));
        $caffe = collect($page['teams'])->firstWhere('id', 5001);
        $this->assertSame(301, $caffe['club']['id']);
        $this->assertSame(['Presidente' => 'Anna Verdi', 'Allenatore' => 'Paolo Neri'], $caffe['staff']);
        $this->assertSame([['url' => 'https://cdn.enjore.com/wl/xfivesport_it/doc/tournament_doc/901-regolamento.pdf', 'title' => 'Regolamento']], $page['documents']);
        $this->assertSame([90002, 90001], array_column($page['latest'], 'id'));
        $this->assertSame([90003], array_column($page['upcoming'], 'id'));

        $this->getJson(self::BASE.'/tournaments/902')->assertNotFound();
    }

    public function test_the_tournament_calendar_is_grouped_by_round(): void
    {
        $rounds = $this->data('/tournaments/901/matches');
        $this->assertSame([1, 2], array_column($rounds, 'round'));
        $this->assertSame(['1ª giornata', '2ª giornata'], array_column($rounds, 'label'));
        $this->assertSame([90002, 90003], array_column($rounds[1]['matches'], 'id'), 'in ordine di orario');
        $this->getJson(self::BASE.'/tournaments/902/matches')->assertNotFound();
    }

    public function test_the_tournament_stats_have_the_xfive_tables_and_the_ones_from_the_reports(): void
    {
        $stats = $this->data('/tournaments/901/stats');
        $this->assertSame(['score', 'top-player', 'discipline'], array_column($stats['tables'], 'type'));
        $score = $stats['tables'][0];
        $this->assertSame(['Goal'], $score['columns']);
        $this->assertSame(['position' => 1, 'name' => 'Verdi L.', 'team' => 'CAFFÈ KM0', 'player_id' => 7001, 'values' => [2]], collect($score['rows'][0])->except('photo_url')->all(), 'la riga abbreviata di XFive si abbina al profilo attraverso la rosa');
        $this->assertSame([], $stats['tables'][1]['rows']);

        $from = $stats['from_reports'];
        $this->assertNotNull($from);
        $this->assertSame([[7002, 3], [7001, 2]], array_map(fn ($r) => [$r['player_id'], $r['values'][0]], $from['scorers']), 'Neri 1+2 gol, Verdi 2+0; il giocatore fuori rosa non c\'è');
        $this->assertSame(['CAFFÈ KM0', 'CAFFÈ KM0'], array_column($from['scorers'], 'team'));
        $this->assertSame([7002, 7001], array_column($from['mvp'], 'player_id'), 'un premio a testa: a parità conta il nome');
        $this->assertSame([['player_id' => 7002, 'values' => [1, 0]]], array_map(fn ($r) => collect($r)->only('player_id', 'values')->all(), $from['cards']));
    }

    public function test_clubs_can_be_listed_and_searched(): void
    {
        $this->assertSame(['BIANCHI', 'CAFFÈ KM0', 'ROSSI'], array_column($this->data('/clubs'), 'name'));
        $this->assertSame([302], array_column($this->data('/clubs?q=ross'), 'id'));
    }

    public function test_the_club_page_has_seasons_roster_record_and_latest_matches(): void
    {
        $page = $this->data('/clubs/301');
        $this->assertSame(['club', 'seasons', 'roster', 'record', 'latest'], array_keys($page));
        $this->assertSame(['id' => 301, 'name' => 'CAFFÈ KM0'], collect($page['club'])->only('id', 'name')->all());

        $this->assertCount(1, $page['seasons']);
        $this->assertSame('2026/2027', $page['seasons'][0]['season']);
        $this->assertSame(['position' => 1, 'teams' => 3, 'team_id' => 5001], collect($page['seasons'][0]['tournaments'][0])->except('tournament')->all());
        $this->assertSame(901, $page['seasons'][0]['tournaments'][0]['tournament']['id']);

        $this->assertSame(5001, $page['roster']['team_id']);
        $this->assertSame(901, $page['roster']['tournament']['id']);
        $this->assertSame([['tpid' => 61002, 'player_id' => 7002, 'name' => 'Neri Marco', 'role' => null, 'number' => null, 'photo_url' => null, 'country' => 'Italia'], ['tpid' => 61001, 'player_id' => 7001, 'name' => 'Verdi Luca', 'role' => 'Attaccante', 'number' => '9', 'photo_url' => 'https://cdn.enjore.com/wl/xfivesport_it/img/player/q/7001-verdi.png', 'country' => 'Italia']], $page['roster']['players']);

        $record = $page['record'];
        $this->assertSame(['played' => 2, 'won' => 1, 'drawn' => 1, 'lost' => 0, 'goals_for' => 5, 'goals_against' => 3], collect($record)->except('biggest_win', 'best_streak')->all());
        $this->assertSame(90001, $record['biggest_win']['id']);
        $this->assertSame(1, $record['best_streak']);
        $this->assertSame([90002, 90001], array_column($page['latest'], 'id'));

        // un torneo che il club non ha giocato: la rosa resta quella della stagione più recente
        $this->assertSame(5001, $this->data('/clubs/301?tournament=999')['roster']['team_id']);
        // un club con il calendario ma senza rosa: niente roster, il record c'è lo stesso
        $bianchi = $this->data('/clubs/302');
        $this->assertSame(5002, $bianchi['roster']['team_id']);
        $this->assertSame(1, $bianchi['record']['lost']);
        $this->getJson(self::BASE.'/clubs/999')->assertNotFound();
    }

    public function test_club_matches_and_head_to_head(): void
    {
        $this->assertSame([90002, 90001], array_column($this->data('/clubs/301/matches'), 'id'));
        $this->assertSame([90003, 90001], array_column($this->data('/clubs/302/matches?tournament=901'), 'id'));
        $this->assertSame([], $this->data('/clubs/302/matches?tournament=999'));

        $h2h = $this->data('/clubs/301/head-to-head/302');
        $this->assertSame(['played' => 1, 'wins_a' => 1, 'wins_b' => 0, 'draws' => 0], collect($h2h)->except('matches')->all());
        $this->assertSame([90001], array_column($h2h['matches'], 'id'));
        $this->assertSame(['played' => 0, 'wins_a' => 0, 'wins_b' => 0, 'draws' => 0], collect($this->data('/clubs/302/head-to-head/303'))->except('matches')->all(), 'la partita in programma conta solo nell\'elenco');
    }

    public function test_players_can_be_searched_by_name_and_club(): void
    {
        $this->assertSame([7002, 7001], array_column($this->data('/players'), 'id'));
        $this->assertSame([['id' => 7001, 'name' => 'Verdi Luca', 'photo_url' => 'https://cdn.enjore.com/wl/xfivesport_it/img/player/q/7001-verdi.png', 'nationality' => 'Italia']], $this->data('/players?q=verdi'));
        $this->assertSame([7002, 7001], array_column($this->data('/players?club=301'), 'id'));
        $this->assertSame([], $this->data('/players?club=302'), 'la rosa di ROSSI non è abbinata a nessun profilo');
    }

    public function test_the_player_page_has_totals_seasons_partners_victims_and_recent_matches(): void
    {
        $page = $this->data('/players/7001');
        $this->assertSame(['player', 'totals', 'by_season', 'partners', 'victims', 'recent'], array_keys($page));
        $this->assertSame(['id' => 7001, 'name' => 'Verdi Luca', 'nationality' => 'Italia', 'age' => 30], collect($page['player'])->only('id', 'name', 'nationality', 'age')->all());
        $this->assertSame(301, $page['player']['clubs'][0]['club']['id']);
        $this->assertSame('CAFFÈ KM0', $page['player']['clubs'][0]['club']['name']);
        $this->assertSame([901], array_column($page['player']['clubs'][0]['tournaments'], 'id'));

        $this->assertSame(['matches' => 2, 'goals' => 2, 'yellow' => 0, 'red' => 0, 'mvp' => 1, 'wins' => 1, 'draws' => 1, 'losses' => 0, 'goals_per_match' => 1], $page['totals']);
        $this->assertSame([['season' => '2026/2027', 'matches' => 2, 'goals' => 2, 'yellow' => 0, 'red' => 0, 'mvp' => 1]], $page['by_season']);
        $this->assertSame([], $page['partners'], 'Neri ha giocato con lui due volte, meno di sei');
        $this->assertSame([['club' => ['id' => 302, 'name' => 'ROSSI', 'badge_url' => 'https://cdn.enjore.com/wl/xfivesport_it/img/team/badge/s/302rossi.png'], 'goals' => 2, 'matches' => 1]], $page['victims']);
        $this->assertSame([[90002, 0, false], [90001, 2, true]], array_map(fn ($m) => [$m['id'], $m['goals'], $m['mvp']], $page['recent']));

        // un profilo senza referti né profilo XFive: tutto a zero, i club dalle rose
        $neri = $this->data('/players/7002');
        $this->assertSame(3, $neri['totals']['goals']);
        $this->assertSame([301], array_column(array_column($neri['player']['clubs'], 'club'), 'id'));
        $this->getJson(self::BASE.'/players/9999')->assertNotFound();
    }

    public function test_matches_by_day_and_the_match_report(): void
    {
        $days = $this->data('/matches?date=2026-10-12');
        $this->assertSame(['2026-10-12', '2026-10-13'], array_column($days, 'date'), 'sette giorni: il 19 resta fuori');
        $this->assertSame([90001], array_column($days[0]['matches'], 'id'));
        $this->assertSame(['2026-10-13', '2026-10-19'], array_column($this->data('/matches?date=2026-10-13'), 'date'));
        $this->assertSame([], $this->data('/matches?date=2026-11-01'));
        $this->assertSame(['2026-10-12', '2026-10-13'], array_column($this->data('/matches?tournament=901'), 'date'), 'di serie i 7 giorni da oggi (il 10): il 19 resta fuori');
        $this->getJson(self::BASE.'/matches?date=ieri')->assertUnprocessable();

        $match = $this->data('/matches/90001');
        $this->assertSame(['match', 'referee', 'home', 'away'], array_keys($match));
        $this->assertSame(90001, $match['match']['id']);
        $this->assertSame('Arbitro Di Prova', $match['referee']);
        $this->assertCount(2, $match['home']['lineup']);
        $this->assertSame(['tpid' => 61001, 'player_id' => 7001, 'name' => 'Verdi Luca', 'goals' => 2, 'yellow' => 0, 'red' => 0, 'mvp' => true, 'photo_url' => 'https://cdn.enjore.com/wl/xfivesport_it/img/player/q/7001-verdi.png'], $match['home']['lineup'][0]);
        $this->assertSame([['tpid' => 61999, 'player_id' => null, 'name' => 'Sconosciuto Ugo', 'goals' => 1, 'yellow' => 0, 'red' => 0, 'mvp' => false, 'photo_url' => null]], $match['away']['lineup'], 'il giocatore fuori rosa compare senza link');

        $this->assertSame(['lineup' => []], $this->data('/matches/90003')['home'], 'senza referto le distinte sono vuote');
        $this->getJson(self::BASE.'/matches/1')->assertNotFound();
    }

    public function test_the_all_time_board_has_the_four_tables_and_the_club_rankings(): void
    {
        $board = $this->data('/stats');
        $this->assertSame(['seasons', 'sports', 'scorers', 'appearances', 'cards', 'mvp', 'best_teams', 'best_defenses'], array_keys($board));
        $this->assertSame(['Calcio a 7 - Maschile'], $board['sports']);
        $this->assertSame(1, $board['seasons'][0]['tournaments']);
        $this->assertSame([7002, 7001], array_column($board['scorers'], 'player_id'));
        $this->assertSame(['position' => 1, 'name' => 'Neri Marco', 'team' => 'CAFFÈ KM0', 'photo_url' => null, 'player_id' => 7002, 'values' => [3]], $board['scorers'][0]);
        $this->assertSame([[7002, 2], [7001, 2]], array_map(fn ($r) => [$r['player_id'], $r['values'][0]], $board['appearances']), 'a parità di presenze, prima chi ha segnato di più');
        $this->assertSame([[7002, [1, 0]]], array_map(fn ($r) => [$r['player_id'], $r['values']], $board['cards']));
        $this->assertSame([7002, 7001], array_column($board['mvp'], 'player_id'));
        $this->assertSame([], $board['best_teams'], 'sotto le dieci partite nessun club entra');
        $this->assertSame([], $board['best_defenses']);

        $this->assertSame([7002, 7001], array_column($this->data('/stats?season=2026/2027&sport=Calcio+a+7+-+Maschile')['scorers'], 'player_id'));
        $this->assertSame([], $this->data('/stats?season=2025/2026')['scorers']);
    }
}
