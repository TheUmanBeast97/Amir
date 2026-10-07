<?php

namespace App\Services;

use App\Models\Competition;
use App\Models\Game;
use App\Models\Player;
use App\Models\SyncRun;
use App\Models\Team;
use App\Models\TeamEvent;
use App\Services\Stats\PlayerStatsService;
use App\Support\Present;

/** Riepilogo per la schermata iniziale dell'area admin. */
final class DashboardService
{
    public function __construct(
        private readonly FinanceService $finance,
        private readonly PlayerStatsService $playerStats,
    ) {}

    /** @return array<string, mixed> */
    public function build(Team $own): array
    {
        $players = Player::where('team_id', $own->id)->where('is_active', true)->orderBy('last_name')->get();
        $squad = $players->where('in_squad_list', true);

        $nextGame = $this->nextOfficialGame($own);
        $nextEvent = TeamEvent::where('team_id', $own->id)
            ->whereNotNull('starts_at')
            ->where('starts_at', '>=', now())
            ->orderBy('starts_at')
            ->first();

        $warnDays = (int) config('amir.cert_warning_days');
        $expiring = $players
            ->filter(fn (Player $p) => $p->medical_cert_expires_on !== null
                && $p->medical_cert_expires_on->lte(now()->addDays($warnDays)->endOfDay()))
            ->sortBy('medical_cert_expires_on')
            ->map(fn (Player $p) => [
                'player' => Present::publicPlayer($p),
                'expires_on' => $p->medical_cert_expires_on->toDateString(),
            ])->values()->all();

        $lastRun = SyncRun::where('status', '!=', 'running')->latest('started_at')->first();

        return [
            'overview' => $this->overview($own, $players, $nextGame),
            'next_match' => $nextGame ? Present::match($nextGame, $own->id) : null,
            'next_event' => $nextEvent ? Present::event($nextEvent) : null,
            'squad_list' => [
                'count' => $squad->count(),
                'limit' => (int) config('amir.squad_list_limit'),
                'players' => $squad->map(fn (Player $p) => Present::publicPlayer($p))->values()->all(),
            ],
            'registrations' => [
                'none' => $players->where('registration_status', 'none')->count(),
                'pending' => $players->where('registration_status', 'pending')->count(),
                'approved' => $players->where('registration_status', 'approved')->count(),
            ],
            'certificates_expiring' => $expiring,
            'birthdays' => $this->birthdays($players),
            'finance' => $this->finance->dashboardTotals($own),
            'deadlines' => collect((array) config('amir.deadlines'))->sortBy('date')->values()->all(),
            'sync' => [
                'last_run_at' => $lastRun?->finished_at?->toIso8601String() ?? $lastRun?->started_at?->toIso8601String(),
                'status' => $lastRun ? ($lastRun->status === 'ok' ? 'ok' : 'error') : 'never',
            ],
            'rules' => (array) config('amir.rules'),
        ];
    }

    /**
     * Chi compie gli anni nei prossimi giorni (il compleanno è un dato personale: sta solo nell'area staff).
     *
     * @param  \Illuminate\Support\Collection<int, Player>  $players  i giocatori attivi
     * @return array<int, array{player: array<string, mixed>, date: string, turns: int, days_left: int}>
     */
    private function birthdays($players, int $withinDays = 45): array
    {
        $today = now()->startOfDay();

        return $players->filter(fn (Player $p) => $p->birth_date !== null)
            ->map(function (Player $p) use ($today) {
                $next = $p->birth_date->copy()->year($today->year)->startOfDay();
                if ($next->lt($today)) {
                    $next = $next->addYear();
                }

                return [
                    'player' => Present::publicPlayer($p),
                    'date' => $next->toDateString(),
                    'turns' => $next->year - $p->birth_date->year,
                    'days_left' => (int) $today->diffInDays($next),
                ];
            })
            ->filter(fn (array $b) => $b['days_left'] <= $withinDays)
            ->sortBy('days_left')
            ->values()
            ->all();
    }

    /**
     * I numeri "da vetrina": bilancio della stagione in corso, forma, leader di sempre
     * e a che punto è la preparazione della prossima partita.
     *
     * @param  \Illuminate\Support\Collection<int, Player>  $players  i giocatori attivi
     * @return array<string, mixed>
     */
    private function overview(Team $own, $players, ?Game $nextGame): array
    {
        $played = fn () => Game::involving($own->id)
            ->where('status', Game::PLAYED)
            ->whereHas('competition', fn ($c) => $c->counted());

        $current = $played()->whereHas('competition', fn ($c) => $c->where('is_current', true))->get();
        $record = ['played' => 0, 'won' => 0, 'drawn' => 0, 'lost' => 0, 'goals_for' => 0, 'goals_against' => 0, 'points' => 0];
        foreach ($current as $g) {
            $isHome = $g->home_team_id === $own->id;
            $for = $isHome ? $g->home_score : $g->away_score;
            $against = $isHome ? $g->away_score : $g->home_score;
            $record['played']++;
            $record['goals_for'] += $for;
            $record['goals_against'] += $against;
            $key = $for > $against ? 'won' : ($for === $against ? 'drawn' : 'lost');
            $record[$key]++;
            $record['points'] += $for > $against ? 3 : ($for === $against ? 1 : 0);
        }

        // ultime 5 partite ufficiali di sempre, dalla più vecchia alla più recente
        $form = $played()->orderByDesc('kickoff_at')->limit(5)->get()
            ->reverse()->map(fn (Game $g) => $g->resultFor($own->id))->values()->all();

        $board = collect($this->playerStats->leaderboard($own));
        $top = fn (string $key) => $board->where('player.is_active', true)->sortByDesc($key)->filter(fn (array $r) => $r[$key] > 0)->take(3)
            ->map(fn (array $r) => ['player' => $r['player'], 'value' => $r[$key]])->values()->all();

        $prep = null;
        if ($nextGame) {
            $nextGame->loadMissing(['lineup', 'event']);
            $prep = [
                'callups_count' => $nextGame->callups()->count(),
                'callups_published' => (bool) $nextGame->callups_published,
                'lineup_saved' => $nextGame->lineup !== null,
                'lineup_published' => (bool) $nextGame->lineup?->is_published,
                'rsvp' => $nextGame->event ? Present::eventSummary($nextGame->event) : null,
            ];
        }

        return [
            'season' => (string) config('amir.xfive.current_season'),
            'record' => $record,
            'form' => $form,
            'leaders' => ['goals' => $top('goals'), 'matches' => $top('matches'), 'mvp' => $top('mvp')],
            'next_match_prep' => $prep,
            'players_count' => $players->count(),
            'former_players_count' => Player::where('team_id', $own->id)->where('is_active', false)->count(),
            'friendlies_upcoming' => Game::involving($own->id)
                ->where('status', Game::SCHEDULED)
                ->where('kickoff_at', '>=', now())
                ->whereHas('competition', fn ($c) => $c->where('kind', Competition::KIND_FRIENDLY))
                ->count(),
        ];
    }

    /** Prima partita con data e ora ufficiali, non ancora giocata. */
    public function nextOfficialGame(Team $own): ?Game
    {
        return Game::with(['competition', 'home', 'away', 'event'])
            ->involving($own->id)
            ->where('status', Game::SCHEDULED)
            ->whereNotNull('kickoff_at')
            ->where('kickoff_at', '>=', now()->subHours(2))
            ->whereHas('competition', fn ($q) => $q->where('is_current', true))
            ->orderBy('kickoff_at')
            ->first();
    }
}
