<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Game;
use App\Models\Team;
use App\Models\TeamEvent;
use App\Services\Xfive\XfiveSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class XfiveSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeXfive();
    }

    private function sync(string $scope = 'current')
    {
        return app(XfiveSyncService::class)->run($scope);
    }

    /** Come la pagina di XFive, ma con solo le prime $n partite. */
    private function firstMatches(string $html, int $n): string
    {
        $parts = preg_split('/(?=<div class="col-xs-12" style="padding-(?:left|right):5px;"><div id="match-)/', $html);

        return $parts[0].implode('', array_slice($parts, 1, $n)).'</div></div></div></div></div></div></body></html>';
    }

    public function test_current_sync_imports_the_partial_calendar(): void
    {
        $run = $this->sync();

        $this->assertSame('ok', $run->status);

        $competition = Competition::where('xfive_tournament_id', 187)->firstOrFail();
        $this->assertSame('2026/2027', $competition->season);
        $this->assertSame('campionato', $competition->kind);
        $this->assertSame(8, $competition->format);
        $this->assertTrue($competition->is_current);
        $this->assertTrue($competition->has_own_team);
        $this->assertSame(18, $competition->total_rounds);

        $this->assertSame(90, Game::where('competition_id', $competition->id)->count());
        $this->assertSame(5, Game::where('status', Game::SCHEDULED)->count());
        $this->assertSame(85, Game::where('status', Game::TO_SCHEDULE)->count());
        $this->assertSame(1, $competition->scheduledRounds());
    }

    public function test_our_team_and_its_only_official_match_are_recognised(): void
    {
        $this->sync();

        $own = Team::where('is_own', true)->firstOrFail();
        $this->assertSame('AMIR COSTRUZIONI', $own->name);
        $this->assertSame(159, $own->xfive_club_id);

        $game = Game::with(['home', 'away'])->involving($own->id)->whereNotNull('kickoff_at')->firstOrFail();
        $this->assertSame('VALONS', $game->away->name);
        $this->assertSame('2026-10-15T20:00:00+02:00', $game->kickoff_at->toIso8601String());
        $this->assertSame('100GRIGIO - CAMPO 4', $game->venue);

        $this->assertSame(1, TeamEvent::count());
        $this->assertSame('AMIR - VALONS', TeamEvent::first()->title);
    }

    public function test_provisional_matches_have_no_date_and_no_event(): void
    {
        $this->sync();

        $provisional = Game::where('status', Game::TO_SCHEDULE)->get();
        $this->assertCount(85, $provisional);
        $this->assertTrue($provisional->every(fn (Game $g) => $g->kickoff_at === null && $g->isProvisional()));
    }

    public function test_running_twice_does_not_duplicate_anything(): void
    {
        $this->sync();
        $second = $this->sync();

        $this->assertSame(90, Game::count());
        $this->assertSame(0, $second->stats['created']);
        $this->assertSame(1, TeamEvent::count());
        $this->assertSame(10, Team::count());
    }

    public function test_a_regenerated_calendar_keeps_the_same_matches(): void
    {
        $this->sync();
        $oldIds = Game::pluck('xfive_match_id')->all();

        // XFive rigenera il calendario: stesse partite, id nuovi
        $this->cittadellaCalendar = preg_replace_callback(
            '/id="match-(\d+)"/',
            fn (array $m) => 'id="match-'.($m[1] + 100000).'"',
            $this->fixture('calendar_cittadella_2026.html'),
        );
        $run = $this->sync();

        $this->assertSame(90, Game::count());
        $this->assertSame(0, Game::where('status', Game::CANCELLED)->count());
        $this->assertSame(0, $run->stats['created']);
        $this->assertEqualsCanonicalizing(
            array_map(fn ($id) => $id + 100000, $oldIds),
            Game::pluck('xfive_match_id')->all(),
        );
    }

    public function test_matches_no_longer_published_are_cancelled_but_an_empty_page_changes_nothing(): void
    {
        $this->sync();

        $this->cittadellaCalendar = $this->firstMatches($this->fixture('calendar_cittadella_2026.html'), 5);
        $run = $this->sync();

        $this->assertSame(85, $run->stats['cancelled']);
        $this->assertSame(85, Game::where('status', Game::CANCELLED)->count());
        $this->assertSame(5, Game::where('status', Game::SCHEDULED)->count());

        // sito in manutenzione / pagina vuota: non si cancella nient'altro
        $this->cittadellaCalendar = '<html><body></body></html>';
        $this->sync();

        $this->assertSame(85, Game::where('status', Game::CANCELLED)->count());
        $this->assertSame(5, Game::where('status', Game::SCHEDULED)->count());
    }

    public function test_played_matches_are_never_cancelled(): void
    {
        $this->cittadellaCalendar = $this->firstMatches($this->fixture('calendar_uispic_first_trimmed.html'), 12);
        $this->sync();

        $played = Game::where('status', Game::PLAYED)->count();
        $this->assertGreaterThan(0, $played);

        $this->cittadellaCalendar = $this->firstMatches($this->fixture('calendar_uispic_first_trimmed.html'), 1);
        $this->sync();

        $this->assertSame($played, Game::where('status', Game::PLAYED)->count());
    }

    public function test_history_sync_imports_past_seasons_without_creating_events(): void
    {
        $run = $this->sync('history');

        $this->assertSame('ok', $run->status);

        $premier = Competition::where('xfive_tournament_id', 144)->firstOrFail();
        $this->assertSame('2025/2026', $premier->season);
        $this->assertFalse($premier->is_current);
        $this->assertSame(7, $premier->format);
        $this->assertGreaterThan(0, Game::where('competition_id', $premier->id)->where('status', Game::PLAYED)->count());
        $this->assertSame(0, TeamEvent::count());
    }

    public function test_a_failing_site_marks_the_run_as_error_without_touching_data(): void
    {
        // il primo Http::fake (setUp) avrebbe la precedenza: si riparte da una facciata pulita
        \Illuminate\Support\Sleep::fake();
        \Illuminate\Support\Facades\Http::swap(new \Illuminate\Http\Client\Factory);
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response('', 500)]);

        $run = $this->sync();

        $this->assertSame('error', $run->status);
        $this->assertNotNull($run->error);
        $this->assertSame(0, Game::count());
    }
}
