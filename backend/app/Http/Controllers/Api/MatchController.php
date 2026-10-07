<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\Lineup;
use App\Models\MatchCallup;
use App\Models\MatchPlayerStat;
use App\Models\Team;
use App\Services\CaptionGenerator;
use App\Services\Stats\HistoryService;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MatchController extends Controller
{
    /** Elenco partite: lo usano sia la parte pubblica sia quella admin. */
    public function index(Request $request): JsonResponse
    {
        $own = Team::ownOrFail();

        $query = Game::with(['competition', 'home', 'away', 'event'])->where('status', '!=', Game::CANCELLED);

        if ($request->filled('competition_id')) {
            $query->where('competition_id', (int) $request->query('competition_id'));
        } elseif ($request->filled('season')) {
            $query->whereHas('competition', fn ($c) => $c->where('season', $request->query('season')));
        } else {
            $query->whereHas('competition', fn ($c) => $c->where('is_current', true)->where('has_own_team', true));
        }

        if ($request->query('scope', 'own') !== 'all') {
            $query->involving($own->id);
        }

        $games = $query->orderBy('round')->orderByRaw('kickoff_at IS NULL')->orderBy('kickoff_at')->orderBy('id')->get();

        return $this->ok($games->map(fn (Game $g) => Present::match($g, $own->id))->values()->all());
    }

    public function show(Game $game, HistoryService $history): JsonResponse
    {
        return $this->ok($this->detail($game, $history));
    }

    public function lineup(Game $game): JsonResponse
    {
        $lineup = $game->lineup;

        return $this->ok($lineup ? Present::lineup($lineup) : null);
    }

    public function saveLineup(Request $request, Game $game): JsonResponse
    {
        $game->loadMissing('competition');
        $format = (int) $game->competition->format;

        $data = $request->validate([
            'formation' => ['required', 'string', 'regex:/^\d(-\d){1,4}$/'],
            'slots' => ['required', 'array', 'size:'.$format],
            'slots.*.slot' => ['required', 'integer', 'between:1,'.$format],
            'slots.*.player_id' => ['nullable', 'integer', 'exists:players,id'],
            'slots.*.label' => ['required', 'string', 'max:8'],
            'slots.*.x' => ['required', 'numeric', 'between:0,100'],
            'slots.*.y' => ['required', 'numeric', 'between:0,100'],
            'bench' => ['nullable', 'array', 'max:30'],
            'bench.*' => ['integer', 'exists:players,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_published' => ['sometimes', 'boolean'],
        ]);

        $outfield = array_sum(array_map('intval', explode('-', $data['formation'])));
        if ($outfield !== $format - 1) {
            throw ValidationException::withMessages([
                'formation' => ['Il modulo deve avere '.($format - 1).' giocatori di movimento, più il portiere.'],
            ]);
        }

        $starters = array_values(array_filter(array_column($data['slots'], 'player_id')));
        $bench = array_values(array_unique($data['bench'] ?? []));

        if (count($starters) !== count(array_unique($starters))) {
            throw ValidationException::withMessages(['slots' => ['Un giocatore compare più volte in formazione.']]);
        }
        if (array_intersect($starters, $bench) !== []) {
            throw ValidationException::withMessages(['bench' => ['Un giocatore non può essere insieme titolare e in panchina.']]);
        }

        $lineup = Lineup::updateOrCreate(
            ['match_id' => $game->id],
            [
                'formation' => $data['formation'],
                'slots' => array_values($data['slots']),
                'bench' => $bench,
                'notes' => $data['notes'] ?? null,
            ] + (array_key_exists('is_published', $data) ? ['is_published' => (bool) $data['is_published']] : []),
        );

        return $this->ok(Present::lineup($lineup));
    }

    /** Divisa della partita e pubblicazione di convocati e formazione sul sito. */
    public function update(Request $request, Game $game, HistoryService $history): JsonResponse
    {
        $data = $request->validate([
            'our_kit' => ['nullable', Rule::in(['red', 'white'])],
            'callups_published' => ['sometimes', 'boolean'],
            'lineup_published' => ['sometimes', 'boolean'],
        ]);

        if (($data['lineup_published'] ?? false) && ! $game->lineup) {
            throw ValidationException::withMessages(['lineup_published' => ['Salva prima la formazione, poi pubblicala.']]);
        }

        if (array_key_exists('our_kit', $data)) {
            $game->our_kit = $data['our_kit'];
        }
        if (array_key_exists('callups_published', $data)) {
            $game->callups_published = (bool) $data['callups_published'];
        }
        $game->save();

        if (array_key_exists('lineup_published', $data) && $game->lineup) {
            $game->lineup->update(['is_published' => (bool) $data['lineup_published']]);
        }

        return $this->ok($this->detail($game->refresh(), $history));
    }

    /** Convocati: l'elenco inviato è quello completo (chi non c'è viene tolto). */
    public function saveCallups(Request $request, Game $game, HistoryService $history): JsonResponse
    {
        $data = $request->validate([
            'players' => ['present', 'array', 'max:40'],
            'players.*.player_id' => ['required', 'integer', 'distinct', 'exists:players,id'],
            'players.*.note' => ['nullable', 'string', 'max:160'],
            'published' => ['sometimes', 'boolean'],
        ]);

        DB::transaction(function () use ($data, $game) {
            $ids = array_column($data['players'], 'player_id');

            foreach ($data['players'] as $row) {
                MatchCallup::updateOrCreate(
                    ['match_id' => $game->id, 'player_id' => $row['player_id']],
                    ['note' => $row['note'] ?? null],
                );
            }
            MatchCallup::where('match_id', $game->id)->whereNotIn('player_id', $ids)->delete();

            if (array_key_exists('published', $data)) {
                $game->update(['callups_published' => (bool) $data['published']]);
            }
        });

        return $this->ok($this->detail($game->refresh(), $history));
    }

    public function saveStats(Request $request, Game $game, HistoryService $history): JsonResponse
    {
        $data = $request->validate([
            'players' => ['required', 'array', 'max:60'],
            'players.*.player_id' => ['required', 'integer', 'exists:players,id'],
            'players.*.played' => ['required', 'boolean'],
            'players.*.goals' => ['sometimes', 'integer', 'min:0', 'max:30'],
            'players.*.assists' => ['sometimes', 'integer', 'min:0', 'max:30'],
            'players.*.yellow' => ['sometimes', 'integer', 'min:0', 'max:5'],
            'players.*.red' => ['sometimes', 'integer', 'min:0', 'max:5'],
            'players.*.rating' => ['nullable', 'numeric', 'between:1,10'],
            'players.*.is_mvp' => ['sometimes', 'boolean'],
            'referee' => ['nullable', 'string', 'max:120'],
            'man_of_the_match_id' => ['nullable', 'integer', 'exists:players,id'],
        ]);

        DB::transaction(function () use ($data, $game, $request) {
            $ids = [];

            foreach ($data['players'] as $row) {
                $ids[] = $row['player_id'];

                MatchPlayerStat::updateOrCreate(
                    ['match_id' => $game->id, 'player_id' => $row['player_id']],
                    [
                        'played' => $row['played'],
                        'goals' => $row['goals'] ?? 0,
                        'assists' => $row['assists'] ?? 0,
                        'yellow' => $row['yellow'] ?? 0,
                        'red' => $row['red'] ?? 0,
                        'rating' => $row['rating'] ?? null,
                        'is_mvp' => $row['is_mvp'] ?? false,
                        // da qui in poi i dati sono dello staff: l'import da XFive non li riscrive più
                        'source' => 'manual',
                    ],
                );
            }

            // l'elenco inviato è l'insieme completo: chi non c'è più va tolto
            MatchPlayerStat::where('match_id', $game->id)->whereNotIn('player_id', $ids)->delete();

            $game->update(array_intersect_key(
                $data,
                array_flip(array_filter(['referee', 'man_of_the_match_id'], fn ($k) => $request->has($k))),
            ));
        });

        return $this->ok($this->detail($game->refresh(), $history));
    }

    public function caption(Request $request, Game $game, CaptionGenerator $captions): JsonResponse
    {
        $data = $request->validate([
            'tone' => ['required', Rule::in(CaptionGenerator::TONES)],
            // per quale grafica dello Studio si scrive, e indicazioni libere dello staff
            'kind' => ['nullable', Rule::in(array_keys(CaptionGenerator::KINDS))],
            'notes' => ['nullable', 'string', 'max:400'],
        ]);

        return $this->ok($captions->generate($game, Team::ownOrFail(), $data['tone'], $data['kind'] ?? null, $data['notes'] ?? null));
    }

    /** @return array<string, mixed> */
    private function detail(Game $game, HistoryService $history): array
    {
        $own = Team::ownOrFail();
        $game->load(['competition', 'home', 'away', 'event', 'lineup', 'stats', 'callups']);

        $isOwn = $game->home_team_id === $own->id || $game->away_team_id === $own->id;
        $opponent = $isOwn ? ($game->home_team_id === $own->id ? $game->away : $game->home) : null;

        return [
            'match' => Present::match($game, $own->id),
            'event' => $game->event ? Present::event($game->event) : null,
            'responses' => $game->event ? Present::responsesFor($game->event) : [],
            'lineup' => $game->lineup ? Present::lineup($game->lineup) : null,
            'stats' => $game->stats->map(fn (MatchPlayerStat $s) => [
                'player_id' => $s->player_id,
                'played' => (bool) $s->played,
                'goals' => $s->goals,
                'assists' => $s->assists,
                'yellow' => $s->yellow,
                'red' => $s->red,
                'rating' => $s->rating,
                'is_mvp' => (bool) $s->is_mvp,
                'source' => $s->source,
            ])->values()->all(),
            'callups' => $game->callups->map(fn (MatchCallup $c) => ['player_id' => $c->player_id, 'note' => $c->note])->values()->all(),
            'callups_published' => (bool) $game->callups_published,
            'our_kit' => $game->our_kit,
            'referee' => $game->referee,
            'man_of_the_match_id' => $game->man_of_the_match_id,
            'head_to_head' => $opponent ? $history->headToHead($own, $opponent) : null,
        ];
    }
}
