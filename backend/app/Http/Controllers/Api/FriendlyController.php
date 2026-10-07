<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Competition;
use App\Models\Game;
use App\Models\Team;
use App\Models\TeamEvent;
use App\Support\Present;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Amichevoli: partite organizzate da noi (non su XFive). Sono partite vere, in una
 * competizione "Amichevoli" per stagione che non conta per storico e statistiche.
 * Ogni amichevole ha un evento collegato, così funzionano RSVP, presenze e promemoria.
 */
class FriendlyController extends Controller
{
    private const WITH = ['competition', 'home', 'away', 'event'];

    public function index(): JsonResponse
    {
        $own = Team::ownOrFail();

        $games = Game::with(self::WITH)
            ->involving($own->id)
            ->whereHas('competition', fn ($c) => $c->where('kind', Competition::KIND_FRIENDLY))
            ->orderByDesc('kickoff_at')
            ->get();

        return $this->ok($games->map(fn (Game $g) => $this->present($g, $own))->values()->all());
    }

    public function store(Request $request): JsonResponse
    {
        $own = Team::ownOrFail();
        $data = $request->validate($this->rules(true));
        $this->guardScores($data);

        $game = DB::transaction(function () use ($data, $own) {
            $kickoff = Carbon::parse($data['kickoff_at']);
            $opponent = $this->opponent($data['opponent_name']);
            $isHome = $data['is_home'] ?? true;
            $hasScore = isset($data['home_score'], $data['away_score']);

            $game = Game::create([
                'competition_id' => $this->competition($kickoff)->id,
                'round_label' => 'Amichevole',
                'home_team_id' => $isHome ? $own->id : $opponent->id,
                'away_team_id' => $isHome ? $opponent->id : $own->id,
                'kickoff_at' => $kickoff,
                'venue' => $data['venue'] ?? null,
                'home_score' => $hasScore ? $data['home_score'] : null,
                'away_score' => $hasScore ? $data['away_score'] : null,
                'status' => $hasScore ? Game::PLAYED : Game::SCHEDULED,
                'our_kit' => $data['our_kit'] ?? null,
            ]);

            $this->syncEvent($game->load(['home', 'away']), $own);

            return $game;
        });

        return $this->ok($this->present($game->refresh()->load(self::WITH), $own), 201);
    }

    public function update(Request $request, Game $game): JsonResponse
    {
        $own = Team::ownOrFail();
        $this->ensureFriendly($game);
        $data = $request->validate($this->rules(false));
        $this->guardScores($data);

        DB::transaction(function () use ($data, $game, $own) {
            $game->loadMissing(['home', 'away']);
            $isHome = array_key_exists('is_home', $data) ? (bool) $data['is_home'] : $game->home_team_id === $own->id;
            $currentOpponent = $game->home_team_id === $own->id ? $game->away : $game->home;
            $opponent = isset($data['opponent_name']) ? $this->opponent($data['opponent_name']) : $currentOpponent;

            $attributes = [
                'home_team_id' => $isHome ? $own->id : $opponent->id,
                'away_team_id' => $isHome ? $opponent->id : $own->id,
            ];
            foreach (['venue', 'our_kit'] as $field) {
                if (array_key_exists($field, $data)) {
                    $attributes[$field] = $data[$field];
                }
            }
            if (isset($data['kickoff_at'])) {
                $kickoff = Carbon::parse($data['kickoff_at']);
                $attributes['kickoff_at'] = $kickoff;
                $attributes['competition_id'] = $this->competition($kickoff)->id;
            }

            // risultato: i due punteggi insieme, oppure entrambi vuoti per tornare "da giocare"
            if (array_key_exists('home_score', $data) || array_key_exists('away_score', $data)) {
                $hasScore = isset($data['home_score'], $data['away_score']);
                $attributes += [
                    'home_score' => $hasScore ? $data['home_score'] : null,
                    'away_score' => $hasScore ? $data['away_score'] : null,
                    'status' => $hasScore ? Game::PLAYED : Game::SCHEDULED,
                ];
            }

            $game->update($attributes);
            $this->syncEvent($game->refresh()->load(['home', 'away']), $own);
        });

        return $this->ok($this->present($game->refresh()->load(self::WITH), $own));
    }

    public function destroy(Game $game): JsonResponse
    {
        $this->ensureFriendly($game);

        DB::transaction(function () use ($game) {
            TeamEvent::where('match_id', $game->id)->delete();
            $game->delete();
        });

        return $this->ok(new \stdClass);
    }

    /** @return array<string, mixed> */
    private function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'opponent_name' => [$required, 'string', 'min:2', 'max:120'],
            'kickoff_at' => [$required, 'date'],
            'venue' => ['nullable', 'string', 'max:160'],
            'is_home' => ['sometimes', 'boolean'],
            'home_score' => ['nullable', 'integer', 'min:0', 'max:99'],
            'away_score' => ['nullable', 'integer', 'min:0', 'max:99'],
            'our_kit' => ['nullable', Rule::in(['red', 'white'])],
        ];
    }

    /** @param array<string, mixed> $data */
    private function guardScores(array $data): void
    {
        $home = $data['home_score'] ?? null;
        $away = $data['away_score'] ?? null;

        if (($home === null) !== ($away === null)) {
            throw ValidationException::withMessages(['home_score' => ['Inserisci entrambi i punteggi, oppure nessuno.']]);
        }
    }

    private function ensureFriendly(Game $game): void
    {
        $game->loadMissing('competition');
        abort_unless($game->competition->kind === Competition::KIND_FRIENDLY, 404);
    }

    /** La squadra avversaria: se esiste già (anche da XFive) con lo stesso nome si riusa, così resta lo stemma. */
    private function opponent(string $name): Team
    {
        $name = trim($name);

        return Team::where('is_own', false)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first()
            ?? Team::create([
                'name' => mb_strtoupper($name),
                'short_name' => Str::limit(mb_strtoupper($name), 18, ''),
                'is_own' => false,
                'format' => (int) config('amir.own.format'),
            ]);
    }

    /** Una competizione "Amichevoli" per stagione (luglio-giugno). */
    private function competition(Carbon $kickoff): Competition
    {
        $start = $kickoff->month >= 7 ? $kickoff->year : $kickoff->year - 1;
        $season = $start.'/'.($start + 1);

        return Competition::firstOrCreate(
            ['kind' => Competition::KIND_FRIENDLY, 'season' => $season],
            [
                'name' => 'Amichevoli',
                'format' => (int) config('amir.own.format'),
                'is_current' => $season === config('amir.xfive.current_season'),
                'has_own_team' => true,
            ],
        );
    }

    private function syncEvent(Game $game, Team $own): void
    {
        $opponent = $game->home_team_id === $own->id ? $game->away : $game->home;
        $title = $game->home_team_id === $own->id
            ? "{$own->short_name} - {$opponent->name} (amichevole)"
            : "{$opponent->name} - {$own->short_name} (amichevole)";

        TeamEvent::updateOrCreate(
            ['match_id' => $game->id],
            ['team_id' => $own->id, 'type' => 'match', 'title' => $title, 'starts_at' => $game->kickoff_at, 'venue' => $game->venue],
        );
    }

    /** @return array<string, mixed> */
    private function present(Game $game, Team $own): array
    {
        return [
            'match' => Present::match($game, $own->id),
            'event' => $game->event ? Present::event($game->event) : null,
        ];
    }
}
