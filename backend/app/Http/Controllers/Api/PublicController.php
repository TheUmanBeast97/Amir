<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Competition;
use App\Models\Game;
use App\Models\Player;
use App\Models\Team;
use App\Services\DashboardService;
use App\Services\IcsBuilder;
use App\Services\MatchReport;
use App\Services\Stats\HistoryService;
use App\Services\Stats\PlayerStatsService;
use App\Services\Stats\StandingsCalculator;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Endpoint senza login: solo dati adatti a un sito pubblico. */
class PublicController extends Controller
{
    private const WITH = ['competition', 'home', 'away', 'event'];

    public function home(DashboardService $dashboard, StandingsCalculator $standings): JsonResponse
    {
        $own = Team::ownOrFail();
        $currentIds = Competition::where('is_current', true)->where('has_own_team', true)->pluck('id');
        $competition = $this->mainCompetition();

        $next = $dashboard->nextOfficialGame($own);

        $played = fn () => Game::with(self::WITH)
            ->involving($own->id)
            ->whereIn('competition_id', $currentIds)
            ->where('status', Game::PLAYED)
            ->orderByDesc('kickoff_at');

        $upcoming = Game::with(self::WITH)
            ->involving($own->id)
            ->whereIn('competition_id', $currentIds)
            ->whereIn('status', [Game::SCHEDULED, Game::TO_SCHEDULE])
            ->orderByRaw('kickoff_at IS NULL')
            ->orderBy('kickoff_at')
            ->orderBy('round')
            ->limit(5)
            ->get();

        $rows = $competition ? $standings->forCompetition($competition) : [];
        // finché nessuno ha giocato, "la nostra posizione" non esiste
        $mine = collect($rows)->sum('played') > 0
            ? collect($rows)->first(fn (array $r) => $r['team']->id === $own->id)
            : null;

        return $this->ok([
            'team' => Present::team($own),
            'competition' => $competition ? Present::competition($competition) : null,
            'calendar_info' => $competition ? $this->calendarInfo($competition) : null,
            'next_match' => $next ? Present::match($next, $own->id) : null,
            'last_match' => ($last = $played()->first()) ? Present::match($last, $own->id) : null,
            'standing' => $mine ? Present::standingRow($mine) : null,
            'standings' => array_map(fn (array $r) => Present::standingRow($r), $rows),
            'upcoming' => $upcoming->map(fn (Game $g) => Present::match($g, $own->id))->values()->all(),
            'recent' => $played()->limit(5)->get()->map(fn (Game $g) => Present::match($g, $own->id))->values()->all(),
        ]);
    }

    public function standings(Request $request, StandingsCalculator $standings): JsonResponse
    {
        $competition = $request->filled('competition_id')
            ? Competition::findOrFail((int) $request->query('competition_id'))
            : $this->mainCompetition();

        $rows = $competition ? $standings->forCompetition($competition) : [];

        return $this->ok(array_map(fn (array $r) => Present::standingRow($r), $rows));
    }

    public function roster(): JsonResponse
    {
        $own = Team::ownOrFail();

        $players = Player::where('team_id', $own->id)
            ->where('is_active', true)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        return $this->ok($players->map(fn (Player $p) => Present::publicPlayer($p))->values()->all());
    }

    public function calendar(IcsBuilder $ics): Response
    {
        return response($ics->forTeam(Team::ownOrFail()), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="amir-calendario.ics"',
        ]);
    }

    public function history(HistoryService $history): JsonResponse
    {
        return $this->ok($history->overview(Team::ownOrFail()));
    }

    public function historyCompetition(Competition $competition, HistoryService $history): JsonResponse
    {
        // le competizioni escluse (Serie A a 8) non si mostrano nello storico
        abort_unless($competition->has_own_team && ! $competition->is_excluded, 404);

        return $this->ok($history->competitionDetail($competition, Team::ownOrFail()));
    }

    public function headToHead(Team $team, HistoryService $history): JsonResponse
    {
        $own = Team::ownOrFail();
        abort_if($team->id === $own->id, 404);

        return $this->ok($history->headToHead($own, $team));
    }

    /** Stemma salvato in locale: stessa origine dell'API (con CORS), utile per esportare le grafiche. */
    public function badge(Team $team): BinaryFileResponse
    {
        abort_unless($team->badge_path && Storage::disk('local')->exists($team->badge_path), 404);

        return $this->image($team->badge_path);
    }

    public function photo(Player $player): BinaryFileResponse
    {
        abort_unless($player->photo_path && Storage::disk('local')->exists($player->photo_path), 404);

        return $this->image($player->photo_path);
    }

    /** Immagine salvata in locale, con tipo dichiarato dall'estensione e cache di un giorno. */
    private function image(string $path): BinaryFileResponse
    {
        return response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => str_ends_with($path, '.jpg') ? 'image/jpeg' : 'image/png',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /**
     * Scheda personale di un giocatore (anche di chi non è più in rosa): numeri complessivi,
     * stagione per stagione, forma, impatto sulla squadra e record.
     */
    public function playerProfile(Player $player, PlayerStatsService $stats): JsonResponse
    {
        $own = Team::ownOrFail();
        abort_unless($player->team_id === $own->id, 404);

        return $this->ok($stats->profile($player, $own));
    }

    /** Classifica di sempre: presenze, gol e cartellini di tutti i giocatori, anche degli ex. */
    public function careerStats(PlayerStatsService $stats): JsonResponse
    {
        return $this->ok($stats->leaderboard(Team::ownOrFail()));
    }

    /** Scheda di una nostra partita: convocati e formazione solo se pubblicati, referto se giocata. */
    public function match(Game $game, MatchReport $report): JsonResponse
    {
        $own = Team::ownOrFail();
        abort_unless($game->home_team_id === $own->id || $game->away_team_id === $own->id, 404);
        abort_if($game->status === Game::CANCELLED, 404);

        return $this->ok($report->publicView($game, $own));
    }

    /** Il campionato in corso (o, in mancanza, un'altra competizione corrente). */
    private function mainCompetition(): ?Competition
    {
        return Competition::where('is_current', true)
            ->where('has_own_team', true)
            ->orderByRaw("kind = 'campionato' DESC")
            ->orderBy('id')
            ->first();
    }

    /** @return array<string, mixed> */
    private function calendarInfo(Competition $competition): array
    {
        $scheduled = $competition->scheduledRounds();
        $complete = ! Game::where('competition_id', $competition->id)->where('status', Game::TO_SCHEDULE)->exists();

        $note = null;
        if (! $complete) {
            $note = $competition->total_rounds
                ? "Calendario ufficiale ancora in aggiornamento: XFive ha pubblicato {$scheduled} giornate su {$competition->total_rounds}."
                : "Calendario ufficiale ancora in aggiornamento: XFive ha pubblicato {$scheduled} giornate.";
        }

        return [
            'competition_id' => $competition->id,
            'scheduled_rounds' => $scheduled,
            'total_rounds' => $competition->total_rounds,
            'is_complete' => $complete,
            'note' => $note,
        ];
    }
}
