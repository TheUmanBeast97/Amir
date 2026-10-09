<?php

namespace App\Services\Zone;

use App\Models\Zone\XfClub;
use App\Models\Zone\XfDocument;
use App\Models\Zone\XfMatch;
use App\Models\Zone\XfMatchPlayer;
use App\Models\Zone\XfPlayer;
use App\Models\Zone\XfSeason;
use App\Models\Zone\XfStanding;
use App\Models\Zone\XfTeam;
use App\Models\Zone\XfTeamPlayer;
use App\Models\Zone\XfTournament;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Le letture dell'API pubblica della Mixed Zone (/public/zone/...) e i presentatori che trasformano i modelli xf_* negli
 * oggetti del contratto frontend/src/api/zone-types.ts: i nomi dei campi devono restare identici ai tipi TypeScript.
 * Niente N+1: le liste di partite leggono tornei e club con due sole query, qualunque sia la loro lunghezza.
 */
final class ZoneQueries
{
    /** Le etichette delle colonne di classifica (XFive non le salva: si ricavano dalle chiavi di `values`). */
    public const STANDING_LABELS = ['Pt' => 'Punti', 'G' => 'Giocate', 'V' => 'Vittorie', 'N' => 'Pareggi', 'P' => 'Sconfitte', 'F' => 'Gol fatti', 'S' => 'Gol subiti', '+/-' => 'Differenza reti', 'FP' => 'Fair play'];

    /** Prima i tornei in corso, poi quelli in arrivo, infine quelli finiti. */
    private const STATUS_RANK = "case status when 'ongoing' then 0 when 'incoming' then 1 else 2 end";

    /** @var array<int,string>|null id stagione => etichetta, letto una volta per richiesta */
    private ?array $seasonLabels = null;

    // ------------------------------------------------------------------ presentatori

    /** @return array<int,string> id stagione => «2026/2027» */
    public function seasonLabels(): array
    {
        return $this->seasonLabels ??= XfSeason::query()->pluck('label', 'id')->map(fn ($l) => (string) $l)->all();
    }

    /** Le stagioni con il numero di tornei, dalla più recente (ZoneSeason[]). */
    public function seasons(): array
    {
        return XfSeason::query()->withCount('tournaments')->orderByDesc('label')->get()
            ->map(fn (XfSeason $s) => ['id' => (int) $s->id, 'label' => (string) $s->label, 'tournaments' => (int) $s->tournaments_count])
            ->values()->all();
    }

    /** ZoneClubRef: l'id può mancare (club non abbinato), il nome c'è sempre. */
    public static function clubRef(?int $id, string $name, ?string $badge): array
    {
        return ['id' => $id ?: null, 'name' => $name, 'badge_url' => $badge];
    }

    /** ZoneTournamentRef. */
    public function tournamentRef(XfTournament $t): array
    {
        return [
            'id' => (int) $t->id,
            'name' => (string) $t->name,
            'season' => $this->seasonLabels()[(int) $t->season_id] ?? '',
            'sport' => (string) $t->sport,
            'format' => $t->format !== null ? (int) $t->format : null,
        ];
    }

    /** ZoneTournament: il torneo letto con tournamentsWithCounts() (partite giocate e in calendario). */
    public function tournamentJson(XfTournament $t): array
    {
        return $this->tournamentRef($t) + [
            'slug' => (string) $t->slug,
            'season_id' => (int) $t->season_id,
            'gender' => $t->gender,
            'category' => $t->category,
            'flyer_url' => $t->flyer_url,
            'teams_count' => $t->teams_count !== null ? (int) $t->teams_count : null,
            'dates' => $t->dates,
            'status' => (string) $t->status,
            'played' => (int) ($t->played_count ?? 0),
            'total' => (int) ($t->total_count ?? 0),
        ];
    }

    /** I tornei con il conteggio delle partite (due sottoquery nella stessa select, niente query in più). */
    public static function tournamentsWithCounts(): Builder
    {
        return XfTournament::query()->withCount(['matches as total_count', 'matches as played_count' => fn ($q) => $q->where('played', true)]);
    }

    /**
     * Le partite come ZoneMatch[]: i tornei e gli stemmi dei club si leggono in due sole query.
     *
     * @param  iterable<XfMatch>  $matches
     */
    public function matches(iterable $matches): array
    {
        $list = $matches instanceof Collection ? $matches : collect($matches);
        if ($list->isEmpty()) {
            return [];
        }
        $tournaments = XfTournament::query()->findMany($list->pluck('tournament_id')->unique()->all())->keyBy('id');
        $clubIds = $list->pluck('home_club_id')->merge($list->pluck('away_club_id'))->filter()->unique()->all();
        $badges = $clubIds ? XfClub::query()->whereIn('id', $clubIds)->pluck('badge_url', 'id')->all() : [];

        return $list->map(fn (XfMatch $m) => $this->matchJson($m, $tournaments->get((int) $m->tournament_id), $badges))->values()->all();
    }

    /** Una partita sola come ZoneMatch. */
    public function match(XfMatch $m): array
    {
        return $this->matches([$m])[0];
    }

    /** @param  array<int,?string>  $badges */
    private function matchJson(XfMatch $m, ?XfTournament $t, array $badges): array
    {
        $home = $m->home_club_id ? (int) $m->home_club_id : null;
        $away = $m->away_club_id ? (int) $m->away_club_id : null;

        return [
            'id' => (int) $m->id,
            'tournament' => $t ? $this->tournamentRef($t) : ['id' => (int) $m->tournament_id, 'name' => '', 'season' => '', 'sport' => '', 'format' => null],
            'round' => $m->round !== null ? (int) $m->round : null,
            'round_label' => $m->round_label,
            'home' => self::clubRef($home, (string) $m->home_name, $home ? ($badges[$home] ?? null) : null),
            'away' => self::clubRef($away, (string) $m->away_name, $away ? ($badges[$away] ?? null) : null),
            'kickoff_at' => $m->kickoff_at?->toIso8601String(),
            'kickoff_raw' => $m->kickoff_raw,
            'venue' => $m->venue,
            'home_score' => $m->home_score !== null ? (int) $m->home_score : null,
            'away_score' => $m->away_score !== null ? (int) $m->away_score : null,
            'played' => (bool) $m->played,
            'has_report' => (bool) $m->has_report,
        ];
    }

    /** Le ultime partite giocate (orario più recente; senza orario in coda). */
    private function latest(Builder $base, int $limit): array
    {
        return $this->matches((clone $base)->where('played', true)
            ->orderByRaw('kickoff_at is null')->orderByDesc('kickoff_at')->orderByDesc('id')->limit($limit)->get());
    }

    /** Le prossime partite: non giocate, da adesso in poi (senza orario in coda). */
    private function upcoming(Builder $base, int $limit): array
    {
        return $this->matches((clone $base)->where('played', false)
            ->where(fn (Builder $w) => $w->where('kickoff_at', '>=', now())->orWhereNull('kickoff_at'))
            ->orderByRaw('kickoff_at is null')->orderBy('kickoff_at')->orderBy('round')->orderBy('id')->limit($limit)->get());
    }

    /** Le partite in cui gioca un club, in casa o fuori. */
    private function involving(int $clubId): Builder
    {
        return XfMatch::query()->where(fn (Builder $w) => $w->where('home_club_id', $clubId)->orWhere('away_club_id', $clubId));
    }

    /** Il testo di ricerca pronto per LIKE: normalizzato, senza i jolly. */
    private static function like(string $q): string
    {
        return '%'.str_replace(['%', '_'], ' ', ZoneImporter::normalize($q)).'%';
    }

    // ------------------------------------------------------------------ home e ricerca

    /** ZoneHome: la stagione scelta (di serie la più recente con tornei), ultimi risultati, prossime partite, tornei per sport, totali. */
    public function home(?string $season): array
    {
        $seasons = $this->seasons();
        $current = collect($seasons)->first(fn (array $s) => $s['label'] === $season)
            ?? collect($seasons)->first(fn (array $s) => $s['tournaments'] > 0)
            ?? ($seasons[0] ?? null);

        $tournaments = $current
            ? self::tournamentsWithCounts()->where('season_id', $current['id'])->orderByRaw(self::STATUS_RANK)->orderBy('sport')->orderBy('name')->get()
            : collect();
        $inSeason = XfMatch::query()->whereIn('tournament_id', $tournaments->pluck('id')->all());

        $totals = (array) DB::query()
            ->selectSub(XfTournament::query()->selectRaw('count(*)')->toBase(), 'tournaments')
            ->selectSub(XfClub::query()->selectRaw('count(*)')->toBase(), 'clubs')
            ->selectSub(XfPlayer::query()->selectRaw('count(*)')->toBase(), 'players')
            ->selectSub(XfMatch::query()->selectRaw('count(*)')->toBase(), 'matches')
            ->selectSub(XfMatch::query()->where('has_report', true)->selectRaw('count(*)')->toBase(), 'reports')
            ->first();

        return [
            'seasons' => $seasons,
            'season' => $current['label'] ?? '',
            'latest' => $tournaments->isEmpty() ? [] : $this->latest($inSeason, 12),
            'upcoming' => $tournaments->isEmpty() ? [] : $this->upcoming($inSeason, 12),
            'tournaments' => $tournaments->groupBy('sport')
                ->map(fn (Collection $items, string $sport) => ['sport' => $sport, 'items' => $items->map(fn (XfTournament $t) => $this->tournamentJson($t))->values()->all()])
                ->values()->all(),
            'totals' => array_map('intval', $totals),
        ];
    }

    /** ZoneSearchResult: club, giocatori e tornei (al massimo 10 per tipo), confronto senza accenti né maiuscole. */
    public function search(string $q): array
    {
        if (mb_strlen(ZoneImporter::normalize($q)) < 2) {
            return ['clubs' => [], 'players' => [], 'tournaments' => []];
        }
        $like = self::like($q);

        return [
            'clubs' => XfClub::query()->where('search', 'like', $like)->orderBy('name')->limit(10)->get()
                ->map(fn (XfClub $c) => self::clubRef((int) $c->id, (string) $c->name, $c->badge_url))->values()->all(),
            'players' => XfPlayer::query()->where('search', 'like', $like)->orderBy('name')->limit(10)->get()
                ->map(fn (XfPlayer $p) => ['id' => (int) $p->id, 'name' => (string) $p->name, 'photo_url' => $p->photo_url])->values()->all(),
            'tournaments' => XfTournament::query()->where('search', 'like', $like)->orderByDesc('season_id')->orderBy('name')->limit(10)->get()
                ->map(fn (XfTournament $t) => $this->tournamentRef($t))->values()->all(),
        ];
    }

    // ------------------------------------------------------------------ tornei

    /** ZoneTournament[]: filtri stagione (etichetta) e sport (testo esatto). */
    public function tournaments(?string $season, ?string $sport): array
    {
        $q = self::tournamentsWithCounts();
        if ($season !== null && $season !== '') {
            $id = array_search($season, $this->seasonLabels(), true);
            if ($id === false) {
                return [];
            }
            $q->where('season_id', $id);
        }
        if ($sport !== null && $sport !== '') {
            $q->where('sport', $sport);
        }

        return $q->orderByDesc('season_id')->orderByRaw(self::STATUS_RANK)->orderBy('sport')->orderBy('name')->get()
            ->map(fn (XfTournament $t) => $this->tournamentJson($t))->values()->all();
    }

    /** ZoneTournamentPage (404 se il torneo non c'è). */
    public function tournament(int $id): array
    {
        $t = self::tournamentsWithCounts()->findOrFail($id);
        $base = XfMatch::query()->where('tournament_id', $id);

        return [
            'tournament' => $this->tournamentJson($t),
            'standings' => $this->standings($id),
            'teams' => XfTeam::query()->where('tournament_id', $id)->with('club')->orderBy('name')->get()
                ->map(fn (XfTeam $team) => [
                    'id' => (int) $team->id,
                    'name' => (string) $team->name,
                    'badge_url' => $team->badge_url,
                    'club' => self::clubRef($team->club_id ? (int) $team->club_id : null, (string) ($team->club?->name ?? $team->name), $team->club?->badge_url ?? $team->badge_url),
                    'staff' => (object) ($team->staff ?? []),
                ])->values()->all(),
            'documents' => XfDocument::query()->where('tournament_id', $id)->orderBy('id')->get()
                ->map(fn (XfDocument $d) => ['url' => (string) $d->url, 'title' => $d->title])->values()->all(),
            'latest' => $this->latest($base, 6),
            'upcoming' => $this->upcoming($base, 6),
        ];
    }

    /** ZoneStandings[]: una tabella per girone (group null se il torneo non ha gironi), con la forma delle ultime 5. */
    private function standings(int $tid): array
    {
        $rows = XfStanding::query()->where('tournament_id', $tid)->orderBy('group')->orderBy('position')->get();
        if ($rows->isEmpty()) {
            return [];
        }
        $forms = $this->forms($tid);

        return $rows->groupBy(fn (XfStanding $r) => (string) $r->group)
            ->map(function (Collection $group, string $name) use ($forms) {
                $keys = array_keys((array) $group->first()->values);

                return [
                    'group' => $name === '' ? null : $name,
                    'columns' => array_map(fn (string $k) => ['key' => $k, 'label' => self::STANDING_LABELS[$k] ?? $k], $keys),
                    'rows' => $group->map(fn (XfStanding $r) => [
                        'position' => (int) $r->position,
                        'club' => self::clubRef($r->club_id ? (int) $r->club_id : null, (string) $r->name, $r->badge_url),
                        'values' => (object) array_map(fn ($v) => is_numeric($v) ? $v + 0 : $v, (array) $r->values),
                        'form' => $r->club_id ? ($forms[(int) $r->club_id] ?? []) : [],
                    ])->values()->all(),
                ];
            })->values()->all();
    }

    /**
     * La forma di ogni club del torneo: gli ultimi `n` risultati (W/D/L) dal più vecchio al più recente.
     *
     * @return array<int, list<string>> id club => forma
     */
    public function forms(int $tid, int $n = 5): array
    {
        $played = XfMatch::query()->where('tournament_id', $tid)->where('played', true)
            ->orderByRaw('kickoff_at is null')->orderByDesc('kickoff_at')->orderByDesc('id')
            ->get(['home_club_id', 'away_club_id', 'home_score', 'away_score']);

        $forms = [];
        foreach ($played as $m) {
            foreach ([[$m->home_club_id, $m->home_score, $m->away_score], [$m->away_club_id, $m->away_score, $m->home_score]] as [$club, $for, $against]) {
                if (! $club || count($forms[(int) $club] ?? []) >= $n) {
                    continue;
                }
                $forms[(int) $club][] = $for > $against ? 'W' : ($for === $against ? 'D' : 'L');
            }
        }

        return array_map('array_reverse', $forms);
    }

    /** ZoneRound[]: il calendario completo del torneo per giornata (giornate senza numero in coda). */
    public function rounds(int $tid): array
    {
        XfTournament::query()->findOrFail($tid);
        $matches = XfMatch::query()->where('tournament_id', $tid)
            ->orderByRaw('round is null')->orderBy('round')->orderByRaw('kickoff_at is null')->orderBy('kickoff_at')->orderBy('id')->get();

        return $matches->groupBy(fn (XfMatch $m) => $m->round === null ? 'x' : (string) (int) $m->round)
            ->map(fn (Collection $group) => [
                'round' => $group->first()->round !== null ? (int) $group->first()->round : null,
                'label' => (string) ($group->first(fn (XfMatch $m) => $m->round_label)?->round_label
                    ?? ($group->first()->round !== null ? 'Giornata '.(int) $group->first()->round : 'Da definire')),
                'matches' => $this->matches($group),
            ])->values()->all();
    }

    // ------------------------------------------------------------------ club

    /** ZoneClubRef[]: tutti i club in ordine di nome, oppure quelli che contengono il testo cercato. */
    public function clubs(?string $q): array
    {
        return XfClub::query()
            ->when($q !== null && mb_strlen(ZoneImporter::normalize($q)) >= 1, fn (Builder $b) => $b->where('search', 'like', self::like((string) $q)))
            ->orderBy('name')->get()
            ->map(fn (XfClub $c) => self::clubRef((int) $c->id, (string) $c->name, $c->badge_url))->values()->all();
    }

    /** ZoneClubPage (404 se il club non c'è): stagioni e tornei con la posizione, rosa del torneo scelto, record, ultime partite. */
    public function club(int $id, ?int $tournament): array
    {
        $club = XfClub::query()->findOrFail($id);
        $teams = XfTeam::query()->where('club_id', $id)->get()->keyBy('tournament_id');
        $tids = $teams->keys()->merge($this->involving($id)->distinct()->pluck('tournament_id'))->map(fn ($v) => (int) $v)->unique()->all();
        $tournaments = $tids ? XfTournament::query()->findMany($tids)->keyBy('id') : collect();
        // una classifica a zero partite giocate è solo l'ordine alfabetico: la posizione non si mostra
        $positions = $tids ? XfStanding::query()->whereIn('tournament_id', $tids)->where('club_id', $id)->get(['tournament_id', 'position', 'values'])
            ->filter(fn (XfStanding $s) => (int) (((array) $s->values)['G'] ?? 0) > 0)
            ->pluck('position', 'tournament_id')->all() : [];
        $labels = $this->seasonLabels();
        $rank = ['ongoing' => 0, 'incoming' => 1, 'previous' => 2];

        $seasons = $tournaments->groupBy('season_id')
            ->sortByDesc(fn (Collection $g, $sid) => $labels[(int) $sid] ?? '')
            ->map(fn (Collection $g, $sid) => [
                'season' => $labels[(int) $sid] ?? '',
                'tournaments' => $g->sortBy(fn (XfTournament $t) => ($rank[$t->status] ?? 9).' '.$t->name)->map(fn (XfTournament $t) => [
                    'tournament' => $this->tournamentRef($t),
                    'position' => isset($positions[(int) $t->id]) ? (int) $positions[(int) $t->id] : null,
                    'teams' => $t->teams_count !== null ? (int) $t->teams_count : null,
                    'team_id' => $teams->has((int) $t->id) ? (int) $teams[(int) $t->id]->id : null,
                ])->values()->all(),
            ])->values()->all();

        // la rosa: il torneo chiesto se il club vi ha una squadra, altrimenti quella della stagione più recente
        $rosterTeam = $tournament !== null && $teams->has($tournament) ? $teams[$tournament] : null;
        if (! $rosterTeam && $teams->isNotEmpty()) {
            $rosterTeam = $teams->sortByDesc(fn (XfTeam $t) => ($labels[(int) ($tournaments->get((int) $t->tournament_id)?->season_id ?? 0)] ?? '').' '.str_pad((string) $t->tournament_id, 8, '0', STR_PAD_LEFT))->first();
        }

        return [
            'club' => self::clubRef((int) $club->id, (string) $club->name, $club->badge_url),
            'seasons' => $seasons,
            'roster' => $rosterTeam && $tournaments->has((int) $rosterTeam->tournament_id) ? [
                'team_id' => (int) $rosterTeam->id,
                'tournament' => $this->tournamentRef($tournaments[(int) $rosterTeam->tournament_id]),
                'players' => XfTeamPlayer::query()->where('team_id', $rosterTeam->id)->orderBy('name')->get()
                    ->map(fn (XfTeamPlayer $p) => [
                        'tpid' => (int) $p->tpid,
                        'player_id' => $p->player_id ? (int) $p->player_id : null,
                        'name' => (string) $p->name,
                        'role' => $p->role,
                        'number' => $p->number,
                        'photo_url' => $p->photo_url,
                        'country' => $p->country,
                    ])->values()->all(),
            ] : null,
            'record' => $this->clubRecord($id),
            'latest' => $this->latest($this->involving($id), 6),
        ];
    }

    /** Il record di un club su tutte le partite giocate: bilancio, gol, vittoria più larga, striscia di vittorie più lunga. */
    public function clubRecord(int $clubId): array
    {
        $matches = $this->involving($clubId)->where('played', true)
            ->orderByRaw('kickoff_at is null')->orderBy('kickoff_at')->orderBy('id')->get();

        $record = ['played' => 0, 'won' => 0, 'drawn' => 0, 'lost' => 0, 'goals_for' => 0, 'goals_against' => 0];
        $best = null;
        $bestMargin = 0;
        $streak = 0;
        $bestStreak = 0;
        foreach ($matches as $m) {
            $home = (int) $m->home_club_id === $clubId;
            $for = (int) ($home ? $m->home_score : $m->away_score);
            $against = (int) ($home ? $m->away_score : $m->home_score);
            $record['played']++;
            $record['goals_for'] += $for;
            $record['goals_against'] += $against;
            if ($for > $against) {
                $record['won']++;
                $streak++;
                $bestStreak = max($bestStreak, $streak);
                $margin = $for - $against;
                if ($best === null || $margin > $bestMargin || ($margin === $bestMargin && $for > (int) ($best->home_club_id === $clubId ? $best->home_score : $best->away_score))) {
                    $best = $m;
                    $bestMargin = $margin;
                }
            } else {
                $streak = 0;
                $record[$for === $against ? 'drawn' : 'lost']++;
            }
        }

        return $record + ['biggest_win' => $best ? $this->match($best) : null, 'best_streak' => $bestStreak];
    }

    /** ZoneMatch[]: tutte le partite di un club (eventualmente in un torneo), dalla più recente. */
    public function clubMatches(int $id, ?int $tournament): array
    {
        XfClub::query()->findOrFail($id);

        return $this->matches($this->involving($id)
            ->when($tournament !== null, fn (Builder $q) => $q->where('tournament_id', $tournament))
            ->orderByRaw('kickoff_at is null')->orderByDesc('kickoff_at')->orderByDesc('id')->get());
    }

    /** ZoneHeadToHead: gli scontri diretti fra due club. */
    public function headToHead(int $a, int $b): array
    {
        $matches = XfMatch::query()
            ->where(fn (Builder $w) => $w
                ->where(fn (Builder $x) => $x->where('home_club_id', $a)->where('away_club_id', $b))
                ->orWhere(fn (Builder $x) => $x->where('home_club_id', $b)->where('away_club_id', $a)))
            ->orderByRaw('kickoff_at is null')->orderByDesc('kickoff_at')->orderByDesc('id')->get();

        $h2h = ['played' => 0, 'wins_a' => 0, 'wins_b' => 0, 'draws' => 0];
        foreach ($matches->where('played', true) as $m) {
            $h2h['played']++;
            $forA = (int) ((int) $m->home_club_id === $a ? $m->home_score : $m->away_score);
            $forB = (int) ((int) $m->home_club_id === $a ? $m->away_score : $m->home_score);
            $h2h[$forA > $forB ? 'wins_a' : ($forA < $forB ? 'wins_b' : 'draws')]++;
        }

        return $h2h + ['matches' => $this->matches($matches)];
    }

    // ------------------------------------------------------------------ giocatori e partite

    /** ZonePlayerRef[]: ricerca per nome e/o per club (i giocatori che sono stati in una rosa di quel club). */
    public function players(?string $q, ?int $club): array
    {
        return XfPlayer::query()
            ->when($q !== null && mb_strlen(ZoneImporter::normalize($q)) >= 1, fn (Builder $b) => $b->where('search', 'like', self::like((string) $q)))
            ->when($club !== null, fn (Builder $b) => $b->whereIn('id', XfTeamPlayer::query()
                ->join('xf_teams', 'xf_teams.id', '=', 'xf_team_players.team_id')
                ->where('xf_teams.club_id', $club)->whereNotNull('xf_team_players.player_id')
                ->select('xf_team_players.player_id')))
            ->orderBy('name')->limit($club !== null ? 200 : 50)->get()
            ->map(fn (XfPlayer $p) => self::playerRef($p))->values()->all();
    }

    /** ZonePlayerRef. */
    public static function playerRef(XfPlayer $p): array
    {
        return ['id' => (int) $p->id, 'name' => (string) $p->name, 'photo_url' => $p->photo_url, 'nationality' => $p->nationality];
    }

    /** ZoneMatchPage (404 se la partita non c'è): la partita, l'arbitro e le due distinte del referto. */
    public function matchPage(int $id): array
    {
        $m = XfMatch::query()->findOrFail($id);
        $lineups = XfMatchPlayer::query()->where('match_id', $id)->orderByDesc('goals')->orderBy('id')->get()
            ->groupBy('side')
            ->map(fn (Collection $rows) => $rows->map(fn (XfMatchPlayer $p) => [
                'tpid' => (int) $p->tpid,
                'player_id' => $p->player_id ? (int) $p->player_id : null,
                'name' => (string) $p->name,
                'goals' => (int) $p->goals,
                'yellow' => (int) $p->yellow,
                'red' => (int) $p->red,
                'mvp' => (bool) $p->mvp,
                'photo_url' => $p->photo_url,
            ])->values()->all());

        return [
            'match' => $this->match($m),
            'referee' => $m->referee,
            'home' => ['lineup' => $lineups->get('home', [])],
            'away' => ['lineup' => $lineups->get('away', [])],
        ];
    }

    /** ZoneDay[]: le partite di 7 giorni a partire da `date` (di serie oggi), un elemento per giorno con partite. */
    public function days(?string $date, ?int $tournament): array
    {
        try {
            $start = $date ? CarbonImmutable::createFromFormat('Y-m-d', $date)->startOfDay() : CarbonImmutable::now()->startOfDay();
        } catch (Throwable) {
            $start = CarbonImmutable::now()->startOfDay();
        }

        $matches = XfMatch::query()
            ->where('kickoff_at', '>=', $start)->where('kickoff_at', '<', $start->addDays(7))
            ->when($tournament !== null, fn (Builder $q) => $q->where('tournament_id', $tournament))
            ->orderBy('kickoff_at')->orderBy('id')->get();

        return $matches->groupBy(fn (XfMatch $m) => $m->kickoff_at->toDateString())
            ->map(fn (Collection $group, string $day) => ['date' => $day, 'matches' => $this->matches($group)])
            ->values()->all();
    }
}
