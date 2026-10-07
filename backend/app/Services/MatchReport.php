<?php

namespace App\Services;

use App\Models\Game;
use App\Models\MatchPlayerStat;
use App\Models\Player;
use App\Models\Team;
use App\Services\Stats\HistoryService;
use App\Support\Present;

/**
 * Scheda partita per il sito pubblico. Convocati e formazione si vedono solo se lo
 * staff li ha pubblicati; il referto (marcatori, cartellini, miglior giocatore,
 * arbitro) c'è a partita giocata. Nessun dato privato (telefoni, note, pagamenti).
 */
final class MatchReport
{
    public function __construct(private readonly HistoryService $history) {}

    /** @return array<string, mixed> */
    public function publicView(Game $game, Team $own): array
    {
        $game->loadMissing(['competition', 'home', 'away', 'event', 'lineup', 'callups.player', 'stats.player']);

        $isOwn = $game->home_team_id === $own->id || $game->away_team_id === $own->id;
        $opponent = $isOwn ? ($game->home_team_id === $own->id ? $game->away : $game->home) : null;

        return [
            'match' => Present::match($game, $own->id),
            'callups' => $game->callups_published ? $this->callups($game) : null,
            'lineup' => ($game->lineup && $game->lineup->is_published) ? $this->lineup($game) : null,
            'report' => $game->isPlayed() ? $this->report($game) : null,
            'head_to_head' => $opponent ? $this->history->headToHead($own, $opponent) : null,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function callups(Game $game): array
    {
        return $game->callups
            ->filter(fn ($c) => $c->player !== null)
            ->sortBy(fn ($c) => $c->player->last_name.' '.$c->player->first_name)
            ->map(fn ($c) => ['player' => Present::publicPlayer($c->player), 'note' => $c->note])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function lineup(Game $game): array
    {
        $lineup = $game->lineup;
        $ids = array_values(array_unique(array_merge(
            array_filter(array_column($lineup->slots ?? [], 'player_id')),
            $lineup->bench ?? [],
        )));

        $players = Player::whereIn('id', $ids)->get()
            ->mapWithKeys(fn (Player $p) => [$p->id => Present::publicPlayer($p)]);

        return Present::lineup($lineup) + ['players' => $players->all()];
    }

    /** @return array<string, mixed> */
    private function report(Game $game): array
    {
        $played = $game->stats->filter(fn (MatchPlayerStat $s) => $s->played && $s->player !== null);
        $row = fn (MatchPlayerStat $s) => [
            'player' => Present::publicPlayer($s->player),
            'goals' => (int) $s->goals,
            'yellow' => (int) $s->yellow,
            'red' => (int) $s->red,
            'mvp' => (bool) $s->is_mvp || $game->man_of_the_match_id === $s->player_id,
        ];

        $motm = $game->man_of_the_match_id ? Player::find($game->man_of_the_match_id) : null;

        // la squadra avversaria: marcatori e cartellini dalla pagina XFive, se è stata letta
        $details = $game->details ?? [];
        $oppSide = ($details['own_side'] ?? null) === 'home' ? 'away' : 'home';
        $opp = $details[$oppSide] ?? ['lineup' => [], 'scorers' => []];

        return [
            'referee' => $game->referee,
            'venue' => $game->venue,
            'man_of_the_match' => $motm ? Present::publicPlayer($motm) : null,
            'players' => $played->map($row)->sortByDesc(fn (array $r) => [$r['goals'], $r['mvp']])->values()->all(),
            'scorers' => $played->where('goals', '>', 0)->sortByDesc('goals')->map($row)->values()->all(),
            'opponent' => [
                'scorers' => array_values(array_filter($opp['scorers'] ?? [], fn ($s) => ($s['goals'] ?? 0) > 0)),
                'cards' => array_values(array_map(
                    fn ($p) => ['name' => $p['name'], 'yellow' => $p['yellow'] ?? 0, 'red' => $p['red'] ?? 0],
                    array_filter($opp['lineup'] ?? [], fn ($p) => ($p['yellow'] ?? 0) > 0 || ($p['red'] ?? 0) > 0),
                )),
                'players_count' => count($opp['lineup'] ?? []),
            ],
        ];
    }
}
