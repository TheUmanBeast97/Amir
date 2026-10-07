<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Game;
use App\Models\Team;
use App\Services\Stats\HistoryService;
use App\Services\Stats\StandingsCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatsTest extends TestCase
{
    use RefreshDatabase;

    private function team(string $name, int $club, bool $own = false): Team
    {
        return Team::create(['name' => $name, 'xfive_club_id' => $club, 'is_own' => $own]);
    }

    private function competition(string $season, string $kind = 'campionato', bool $current = false, int $id = 1): Competition
    {
        return Competition::create([
            'xfive_tournament_id' => $id, 'name' => "Torneo {$id}", 'season' => $season,
            'kind' => $kind, 'format' => 8, 'is_current' => $current, 'has_own_team' => true,
        ]);
    }

    private function game(Competition $c, Team $home, Team $away, ?int $hs, ?int $as, int $round = 1): Game
    {
        return Game::create([
            'competition_id' => $c->id, 'round' => $round, 'round_label' => "{$round}ª giornata",
            'home_team_id' => $home->id, 'away_team_id' => $away->id,
            'home_score' => $hs, 'away_score' => $as,
            'kickoff_at' => $hs === null ? null : now()->subDays(30 - $round),
            'status' => $hs === null ? Game::TO_SCHEDULE : Game::PLAYED,
        ]);
    }

    public function test_standings_use_points_then_goal_difference_then_goals_scored(): void
    {
        $a = $this->team('ALFA', 1);
        $b = $this->team('BETA', 2);
        $c = $this->team('GAMMA', 3);
        $comp = $this->competition('2025/2026');

        $this->game($comp, $a, $b, 3, 0, 1);   // A 3 pt (+3)
        $this->game($comp, $c, $a, 1, 2, 2);   // A 6 pt (+4), C 0
        $this->game($comp, $b, $c, 4, 1, 3);   // B 3 pt (+1), C 0

        $rows = (new StandingsCalculator)->forCompetition($comp);

        $this->assertSame(['ALFA', 'BETA', 'GAMMA'], array_map(fn ($r) => $r['team']->name, $rows));
        $this->assertSame([1, 2, 3], array_column($rows, 'position'));
        $this->assertSame(6, $rows[0]['points']);
        $this->assertSame(4, $rows[0]['goal_diff']);
        $this->assertSame(2, $rows[0]['won']);
        $this->assertSame(3, $rows[1]['points']);
    }

    public function test_before_any_match_everyone_shares_first_place(): void
    {
        $a = $this->team('ALFA', 1);
        $b = $this->team('BETA', 2);
        $comp = $this->competition('2026/2027', 'campionato', true);
        $this->game($comp, $a, $b, null, null);

        $rows = (new StandingsCalculator)->forCompetition($comp);

        $this->assertSame([1, 1], array_column($rows, 'position'));
        $this->assertSame([0, 0], array_column($rows, 'played'));
    }

    public function test_history_ranks_leagues_only_and_hides_competitions_without_own_matches(): void
    {
        $own = $this->team('AMIR', 159, true);
        $x = $this->team('XRAY', 2);
        $y = $this->team('YANKEE', 3);

        $league = $this->competition('2024/2025', 'campionato', false, 10);
        $this->game($league, $own, $x, 5, 1, 1);
        $this->game($league, $y, $own, 0, 0, 2);
        $this->game($league, $x, $y, 2, 3, 3);

        $cup = $this->competition('2024/2025', 'coppa_lega', false, 11);
        $this->game($cup, $own, $y, 2, 3, 1);

        $this->competition('2024/2025', 'torneo', false, 12); // nessuna partita: non deve comparire

        $overview = (new HistoryService(new StandingsCalculator))->overview($own);

        $this->assertCount(1, $overview['seasons']);
        $season = $overview['seasons'][0];
        $this->assertSame('2024/2025', $season['season']);
        $this->assertCount(2, $season['competitions']);

        $byKind = array_column($season['competitions'], null, 'kind');
        $this->assertSame(1, $byKind['campionato']['final_position']);
        $this->assertSame(3, $byKind['campionato']['teams_count']);
        $this->assertNull($byKind['coppa_lega']['final_position']);

        $this->assertSame(['played' => 3, 'won' => 1, 'drawn' => 1, 'lost' => 1, 'goals_for' => 7, 'goals_against' => 4, 'points' => 4], $overview['summary']['record']);
        $this->assertSame(1, $overview['summary']['seasons_count']);
        $this->assertSame(5, $overview['summary']['best_win']['home_score']);
        $this->assertSame('YANKEE', $overview['summary']['most_frequent_opponent']['team']['name']);
    }

    public function test_head_to_head_collects_the_matches_of_every_season(): void
    {
        $own = $this->team('AMIR', 159, true);
        $rival = $this->team('RIVALI', 7);

        $this->game($this->competition('2023/2024', 'campionato', false, 20), $own, $rival, 4, 2);
        $this->game($this->competition('2024/2025', 'campionato', false, 21), $rival, $own, 3, 3);
        $this->game($this->competition('2025/2026', 'campionato', false, 22), $rival, $own, 5, 1);

        $h2h = (new HistoryService(new StandingsCalculator))->headToHead($own, $rival);

        $this->assertSame(3, $h2h['played']);
        $this->assertSame([1, 1, 1], [$h2h['won'], $h2h['drawn'], $h2h['lost']]);
        $this->assertSame(8, $h2h['goals_for']);
        $this->assertSame(10, $h2h['goals_against']);
        $this->assertCount(3, $h2h['matches']);
    }
}
