<?php

namespace App\Services\Stats;

use App\Models\EventResponse;
use App\Models\Game;
use App\Models\Lineup;
use App\Models\MatchPlayerStat;
use App\Models\Player;
use App\Models\Team;
use App\Models\TeamEvent;
use App\Support\Present;

/** Classifiche di squadra: presenze, allenamenti, gol, assist, voti, cartellini. */
final class AttendanceStats
{
    /** @return array<int, array<string, mixed>> */
    public function leaderboard(Team $own, ?string $season): array
    {
        $players = Player::where('team_id', $own->id)->where('is_active', true)->get();

        $stats = MatchPlayerStat::query()
            ->join('matches', 'matches.id', '=', 'match_player_stats.match_id')
            ->join('competitions', 'competitions.id', '=', 'matches.competition_id')
            ->when($season, fn ($q) => $q->where('competitions.season', $season))
            ->get(['match_player_stats.*'])
            ->groupBy('player_id');

        $gameIds = Game::query()
            ->join('competitions', 'competitions.id', '=', 'matches.competition_id')
            ->when($season, fn ($q) => $q->where('competitions.season', $season))
            ->pluck('matches.id');

        $called = [];
        foreach (Lineup::whereIn('match_id', $gameIds)->get() as $lineup) {
            $ids = array_merge(
                array_filter(array_column($lineup->slots ?? [], 'player_id')),
                $lineup->bench ?? [],
            );
            foreach ($ids as $pid) {
                $called[$pid][$lineup->match_id] = true;
            }
        }

        [$from, $to] = $this->seasonRange($season);
        $events = TeamEvent::where('team_id', $own->id)
            ->where('starts_at', '<=', now())
            ->when($from, fn ($q) => $q->where('starts_at', '>=', $from)->where('starts_at', '<=', $to))
            ->get();
        $pastEventCount = $events->count();
        $eventById = $events->keyBy('id');

        $responses = EventResponse::whereIn('event_id', $events->pluck('id'))->get()->groupBy('player_id');

        $rows = [];
        foreach ($players as $player) {
            $own_stats = $stats->get($player->id, collect());
            $resp = $responses->get($player->id, collect());

            $calledMatches = collect($called[$player->id] ?? [])->keys()
                ->merge($own_stats->pluck('match_id'))->unique()->count();

            $attended = $resp->filter(fn (EventResponse $r) => $r->attended === true);
            $ratings = $own_stats->pluck('rating')->filter(fn ($r) => $r !== null);

            $rows[] = [
                'player_id' => $player->id,
                'full_name' => $player->full_name,
                'photo_url' => $player->photo_url,
                'matches_played' => $own_stats->where('played', true)->count(),
                'matches_called' => $calledMatches,
                'trainings_attended' => $attended->filter(fn (EventResponse $r) => $eventById->get($r->event_id)?->type === 'training')->count(),
                'events_attended' => $attended->count(),
                'rsvp_yes_rate' => $pastEventCount > 0 ? round($resp->where('rsvp', 'yes')->count() / $pastEventCount, 3) : 0.0,
                'goals' => (int) $own_stats->sum('goals'),
                'assists' => (int) $own_stats->sum('assists'),
                'yellow' => (int) $own_stats->sum('yellow'),
                'red' => (int) $own_stats->sum('red'),
                'avg_rating' => $ratings->isNotEmpty() ? round((float) $ratings->avg(), 2) : null,
            ];
        }

        usort($rows, fn (array $a, array $b) => [$b['matches_played'], $b['events_attended'], $a['full_name']]
            <=> [$a['matches_played'], $a['events_attended'], $b['full_name']]);

        return $rows;
    }

    /** "2026/2027" => [1 luglio 2026, 30 giugno 2027]; null => nessun filtro. */
    private function seasonRange(?string $season): array
    {
        if (! $season || ! preg_match('#^(\d{4})/(\d{4})$#', $season, $m)) {
            return [null, null];
        }

        return [$m[1].'-07-01 00:00:00', $m[2].'-06-30 23:59:59'];
    }
}
