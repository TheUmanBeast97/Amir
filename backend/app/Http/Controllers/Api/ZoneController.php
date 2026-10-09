<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Zone\ZoneQueries;
use App\Services\Zone\ZoneStats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * L'API pubblica della Mixed Zone (/api/v1/public/zone/...): nessun login, solo ciò che XFive pubblica già.
 * Le risposte hanno la forma dei tipi di frontend/src/api/zone-types.ts, dentro { data }.
 */
class ZoneController extends Controller
{
    public function __construct(private readonly ZoneQueries $queries, private readonly ZoneStats $stats) {}

    /** ZoneHome: ?season= (etichetta «2026/2027», di serie la più recente con tornei). */
    public function home(Request $request): JsonResponse
    {
        $request->validate(['season' => ['nullable', 'string', 'max:9']]);

        return $this->ok($this->queries->home($request->query('season')));
    }

    /** ZoneSearchResult: ?q= (almeno 2 lettere). */
    public function search(Request $request): JsonResponse
    {
        $request->validate(['q' => ['nullable', 'string', 'max:80']]);

        return $this->ok($this->queries->search((string) $request->query('q', '')));
    }

    /** ZoneTournament[]: ?season=&sport=. */
    public function tournaments(Request $request): JsonResponse
    {
        $request->validate(['season' => ['nullable', 'string', 'max:9'], 'sport' => ['nullable', 'string', 'max:60']]);

        return $this->ok($this->queries->tournaments($request->query('season'), $request->query('sport')));
    }

    /** ZoneTournamentPage. */
    public function tournament(int $id): JsonResponse
    {
        return $this->ok($this->queries->tournament($id));
    }

    /** ZoneRound[]: il calendario completo per giornata. */
    public function tournamentMatches(int $id): JsonResponse
    {
        return $this->ok($this->queries->rounds($id));
    }

    /** ZoneTournamentStats: le tabelle XFive e quelle ricalcolate dai referti. */
    public function tournamentStats(int $id): JsonResponse
    {
        return $this->ok($this->stats->tournamentStats($id));
    }

    /** ZoneClubRef[]: ?q=. */
    public function clubs(Request $request): JsonResponse
    {
        $request->validate(['q' => ['nullable', 'string', 'max:80']]);

        return $this->ok($this->queries->clubs($request->query('q')));
    }

    /** ZoneClubPage: ?tournament= sceglie la rosa da mostrare. */
    public function club(Request $request, int $id): JsonResponse
    {
        $request->validate(['tournament' => ['nullable', 'integer']]);

        return $this->ok($this->queries->club($id, $request->filled('tournament') ? (int) $request->query('tournament') : null));
    }

    /** ZoneMatch[]: le partite del club, ?tournament= per un torneo solo. */
    public function clubMatches(Request $request, int $id): JsonResponse
    {
        $request->validate(['tournament' => ['nullable', 'integer']]);

        return $this->ok($this->queries->clubMatches($id, $request->filled('tournament') ? (int) $request->query('tournament') : null));
    }

    /** ZoneHeadToHead. */
    public function headToHead(int $id, int $other): JsonResponse
    {
        return $this->ok($this->queries->headToHead($id, $other));
    }

    /** ZonePlayerRef[]: ?q=&club=. */
    public function players(Request $request): JsonResponse
    {
        $request->validate(['q' => ['nullable', 'string', 'max:80'], 'club' => ['nullable', 'integer']]);

        return $this->ok($this->queries->players($request->query('q'), $request->filled('club') ? (int) $request->query('club') : null));
    }

    /** ZonePlayerPage. */
    public function player(int $id): JsonResponse
    {
        return $this->ok($this->stats->player($id));
    }

    /** ZoneDay[]: ?date=AAAA-MM-GG (di serie oggi, 7 giorni) &tournament=. */
    public function matches(Request $request): JsonResponse
    {
        $request->validate(['date' => ['nullable', 'date_format:Y-m-d'], 'tournament' => ['nullable', 'integer']]);

        return $this->ok($this->queries->days($request->query('date'), $request->filled('tournament') ? (int) $request->query('tournament') : null));
    }

    /** ZoneMatchPage: il referto completo. */
    public function match(int $id): JsonResponse
    {
        return $this->ok($this->queries->matchPage($id));
    }

    /** ZoneStatsBoard: ?season=&sport=. */
    public function stats(Request $request): JsonResponse
    {
        $request->validate(['season' => ['nullable', 'string', 'max:9'], 'sport' => ['nullable', 'string', 'max:60']]);

        return $this->ok($this->stats->board($request->query('season'), $request->query('sport')));
    }
}
