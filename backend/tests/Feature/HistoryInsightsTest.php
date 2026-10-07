<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Game;
use App\Models\MatchPlayerStat;
use App\Models\Player;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Storico: record, serie, rendimento, arbitri, campi, triplette e premi, su dati costruiti a mano. */
class HistoryInsightsTest extends TestCase
{
    use RefreshDatabase;

    private Team $own;

    private Team $x;

    private Team $y;

    private Team $z;

    private Competition $league;

    /** @var array<string, Game> */
    private array $g = [];

    private Player $a;

    private Player $b;

    private Player $c;

    protected function setUp(): void
    {
        parent::setUp();
        $this->own = Team::create(['name' => 'AMIR', 'xfive_club_id' => 159, 'is_own' => true]);
        $this->x = Team::create(['name' => 'XRAY', 'xfive_club_id' => 2]);
        $this->y = Team::create(['name' => 'YANKEE', 'xfive_club_id' => 3]);
        $this->z = Team::create(['name' => 'ZULU', 'xfive_club_id' => 4]);

        $this->league = Competition::create([
            'xfive_tournament_id' => 10, 'name' => 'Lega', 'season' => '2025/2026', 'kind' => 'campionato', 'format' => 8, 'is_current' => false, 'has_own_team' => true,
        ]);
        $old = Competition::create([
            'xfive_tournament_id' => 9, 'name' => 'Lega vecchia', 'season' => '2024/2025', 'kind' => 'campionato', 'format' => 7, 'is_current' => false, 'has_own_team' => true,
        ]);

        // dal punto di vista di AMIR: V V V P N V (lunedì, 21:00 o 22:00)
        $this->g['g1'] = $this->game($this->league, $this->own, $this->x, 5, 1, '2025-10-06 21:00:00', 'Rossi', 'CAMPO 1');
        $this->g['g2'] = $this->game($this->league, $this->y, $this->own, 2, 3, '2025-10-13 21:00:00', 'Rossi', 'CAMPO 1');
        $this->g['g3'] = $this->game($this->league, $this->own, $this->z, 3, 0, '2025-10-20 22:00:00', 'Bianchi', 'CAMPO 2');
        $this->g['g4'] = $this->game($this->league, $this->x, $this->own, 4, 1, '2025-10-27 22:00:00', 'Bianchi', 'CAMPO 2');
        $this->g['g5'] = $this->game($this->league, $this->own, $this->y, 0, 0, '2025-11-03 21:00:00', 'Rossi', 'CAMPO 1');
        $this->g['g6'] = $this->game($this->league, $this->z, $this->own, 1, 3, '2025-11-10 21:00:00', null, 'CAMPO 1');
        $this->g['g7'] = $this->game($this->league, $this->x, $this->z, 6, 0, '2025-11-10 22:00:00', null, 'CAMPO 3'); // non è nostra
        $this->g['old'] = $this->game($old, $this->own, $this->x, 1, 2, '2024-11-04 21:00:00', 'Verdi', 'CAMPO 1');

        $this->a = Player::create(['team_id' => $this->own->id, 'first_name' => 'Anna', 'last_name' => 'Alfa']);
        $this->b = Player::create(['team_id' => $this->own->id, 'first_name' => 'Bruno', 'last_name' => 'Beta']);
        $this->c = Player::create(['team_id' => $this->own->id, 'first_name' => 'Carlo', 'last_name' => 'Gamma']);

        $this->stat('g1', $this->a, goals: 2);
        $this->stat('g1', $this->b, goals: 1, yellow: 1);
        $this->stat('g3', $this->a, goals: 3, mvp: true);
        $this->stat('g4', $this->a, goals: 1);
        $this->stat('g4', $this->c, red: 1);
        $this->stat('g6', $this->a);
        $this->stat('g6', $this->b, goals: 1);
        $this->stat('old', $this->c);
    }

    private function game(Competition $c, Team $home, Team $away, int $hs, int $as, string $kickoff, ?string $referee, string $venue): Game
    {
        return Game::create([
            'competition_id' => $c->id, 'round' => 1, 'round_label' => '1ª giornata', 'home_team_id' => $home->id, 'away_team_id' => $away->id,
            'home_score' => $hs, 'away_score' => $as, 'kickoff_at' => $kickoff, 'status' => Game::PLAYED, 'referee' => $referee, 'venue' => $venue,
        ]);
    }

    private function stat(string $game, Player $p, int $goals = 0, int $yellow = 0, int $red = 0, bool $mvp = false): void
    {
        MatchPlayerStat::create(['match_id' => $this->g[$game]->id, 'player_id' => $p->id, 'played' => true, 'goals' => $goals, 'yellow' => $yellow, 'red' => $red, 'is_mvp' => $mvp]);
    }

    /** Le "insights" della stagione 2025/2026 dalla pagina pubblica dello storico. */
    private function season(): array
    {
        $data = $this->getJson('/api/v1/public/history')->assertOk()->json('data');

        return collect($data['seasons'])->firstWhere('season', '2025/2026')['insights'];
    }

    public function test_records_count_goals_clean_sheets_and_cards_and_find_the_extreme_matches(): void
    {
        $r = $this->season()['records'];

        $this->assertSame(2.5, $r['goals_per_match']);          // 15 reti in 6 partite
        $this->assertSame(1.33, $r['conceded_per_match']);      // 8 subite
        $this->assertSame(2.17, $r['points_per_match']);        // 13 punti
        $this->assertSame(2, $r['clean_sheets']);               // 3-0 e 0-0
        $this->assertSame(1, $r['scoreless']);                  // lo 0-0
        $this->assertSame([5, 1], [$r['best_win']['home_score'], $r['best_win']['away_score']]);
        $this->assertSame('XRAY', $r['worst_defeat']['home_team']);
        $this->assertSame([5, 1], [$r['highest_scoring']['home_score'], $r['highest_scoring']['away_score']]);
        $this->assertSame([5, 1], [$r['most_scored']['home_score'], $r['most_scored']['away_score']]);
        $this->assertSame(4, $r['most_conceded']['home_score']);
        $this->assertSame([1, 1], [$r['hat_tricks_count'], $r['doubles_count']]);
        $this->assertSame([1, 1], [$r['yellow'], $r['red']]);
    }

    public function test_streaks_are_the_longest_runs_in_date_order(): void
    {
        $s = $this->season()['streaks'];

        $this->assertSame(['length' => 3, 'from' => '2025-10-06', 'to' => '2025-10-20'], $s['wins']);
        $this->assertSame(3, $s['unbeaten']['length']);
        $this->assertSame(1, $s['losses']['length']);
        $this->assertSame(['length' => 4, 'from' => '2025-10-06', 'to' => '2025-10-27'], $s['scoring'], 'a segno fino allo 0-0');
        $this->assertSame(1, $s['clean_sheets']['length']);
    }

    public function test_splits_by_home_away_slot_weekday_and_month(): void
    {
        $sp = $this->season()['splits'];
        $lines = fn (array $r) => [$r['played'], $r['won'], $r['drawn'], $r['lost'], $r['goals_for'], $r['goals_against']];

        $this->assertSame([3, 2, 1, 0, 8, 1], $lines($sp['home_away']['home']), 'prima nominata: 5-1, 3-0, 0-0');
        $this->assertSame([3, 2, 0, 1, 7, 7], $lines($sp['home_away']['away']), 'seconda nominata: 3-2, 1-4, 3-1');

        $this->assertSame(['21:00' => 4, '22:00' => 2], array_column($sp['by_slot'], 'played', 'label'));
        $this->assertSame([['Lunedì', 6]], array_map(fn ($r) => [$r['label'], $r['played']], $sp['by_weekday']));
        $this->assertSame([['Ottobre', 4], ['Novembre', 2]], array_map(fn ($r) => [$r['label'], $r['played']], $sp['by_month']));
        $this->assertSame([['Calcio a 8', 6]], array_map(fn ($r) => [$r['label'], $r['played']], $sp['by_format']));
        $this->assertSame([['campionato', 6]], array_map(fn ($r) => [$r['label'], $r['played']], $sp['by_kind']));
    }

    public function test_referees_and_venues_are_grouped_and_cards_follow_the_referee(): void
    {
        $i = $this->season();
        $row = fn (array $r) => [$r['played'], $r['won'], $r['drawn'], $r['lost'], $r['yellow'], $r['red']];

        $byRef = array_column($i['referees'], null, 'name');
        $this->assertSame(['Rossi', 'Bianchi'], array_column($i['referees'], 'name'), 'chi ha arbitrato di più per primo; senza arbitro non conta');
        $this->assertSame([3, 2, 1, 0, 1, 0], $row($byRef['Rossi']));
        $this->assertSame([2, 1, 0, 1, 0, 1], $row($byRef['Bianchi']));

        $this->assertSame([['CAMPO 1', 4], ['CAMPO 2', 2]], array_map(fn ($v) => [$v['name'], $v['played']], $i['venues']), 'il campo 3 è di una partita non nostra');
    }

    public function test_hat_tricks_player_records_and_roster(): void
    {
        $i = $this->season();

        $this->assertCount(1, $i['hat_tricks']);
        $this->assertSame(['Anna Alfa', 3, 'Lega'], [$i['hat_tricks'][0]['player']['full_name'], $i['hat_tricks'][0]['goals'], $i['hat_tricks'][0]['match']['competition_name']]);

        $this->assertSame(['Anna Alfa', 6], [$i['player_records']['goals']['player']['full_name'], $i['player_records']['goals']['value']]);
        $this->assertSame(4, $i['player_records']['apps']['value']);
        $this->assertSame(1, $i['player_records']['mvp']['value']);

        $roster = collect($i['roster'])->keyBy(fn ($r) => $r['player']['full_name'])->all();
        $this->assertSame(['Anna Alfa', 'Bruno Beta', 'Carlo Gamma'], array_map(fn ($r) => $r['player']['full_name'], $i['roster']), 'più presenze per prime');
        $this->assertSame([4, 6, 1, 1.5], [$roster['Anna Alfa']['apps'], $roster['Anna Alfa']['goals'], $roster['Anna Alfa']['mvp'], $roster['Anna Alfa']['goals_per_match']]);
        $this->assertSame([2, 2, 1], [$roster['Bruno Beta']['apps'], $roster['Bruno Beta']['goals'], $roster['Bruno Beta']['yellow']]);
        $this->assertSame([1, 1], [$roster['Carlo Gamma']['apps'], $roster['Carlo Gamma']['red']], 'la presenza del 2024/25 è di un\'altra stagione');
    }

    public function test_the_all_time_view_covers_every_season_without_the_roster(): void
    {
        $data = $this->getJson('/api/v1/public/history')->assertOk()->json('data');

        $this->assertSame(['2025/2026', '2024/2025'], array_column($data['seasons'], 'season'));
        $this->assertSame([], $data['insights']['roster'], 'per tutto lo storico c\'è l\'albo d\'oro');
        $this->assertSame(7, collect($data['seasons'])->sum(fn ($s) => $s['record']['played']), 'sei partite nel 2025/26 e una nel 2024/25');
        $this->assertCount(2, $data['insights']['splits']['by_format'], 'calcio a 8 e a 7');
        $this->assertSame(['Rossi', 'Bianchi', 'Verdi'], array_column($data['insights']['referees'], 'name'));
    }

    public function test_the_competition_page_has_the_first_place_best_attack_and_best_defence(): void
    {
        $d = $this->getJson("/api/v1/public/history/competitions/{$this->league->id}")->assertOk()->json('data');
        $a = $d['awards'];

        $this->assertSame(['AMIR', 13, 6], [$a['first_place']['team']['name'], $a['first_place']['value'], $a['first_place']['played']]);
        $this->assertSame(['XRAY', 11, 3], [$a['best_attack']['team']['name'], $a['best_attack']['value'], $a['best_attack']['played']], '11 reti in 3 partite: 3,7 a partita contro 2,5');
        $this->assertSame(['AMIR', 8], [$a['best_defence']['team']['name'], $a['best_defence']['value']], '1,3 subite a partita');
        $this->assertSame([1, 4], [$a['own_position'], $a['teams_count']]);

        $this->assertSame(2, $d['insights']['records']['clean_sheets'], 'le statistiche della competizione sono solo quelle delle nostre partite');
        $this->assertCount(3, $d['insights']['roster']);
    }

    public function test_cups_have_no_awards_because_the_points_table_does_not_say_who_won(): void
    {
        $cup = Competition::create(['xfive_tournament_id' => 77, 'name' => 'Coppa', 'season' => '2025/2026', 'kind' => 'coppa', 'format' => 8, 'is_current' => false, 'has_own_team' => true]);
        $this->game($cup, $this->own, $this->x, 2, 1, '2026-03-02 21:00:00', 'Rossi', 'CAMPO 1');
        $this->game($cup, $this->y, $this->z, 1, 0, '2026-03-02 22:00:00', null, 'CAMPO 2');

        $d = $this->getJson("/api/v1/public/history/competitions/{$cup->id}")->assertOk()->json('data');

        $this->assertNull($d['awards']);
        $this->assertSame(2, $d['insights']['records']['best_win']['home_score']);
    }
}
