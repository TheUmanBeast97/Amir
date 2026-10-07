<?php

namespace App\Services\Xfive;

use App\Models\Competition;
use App\Models\Game;
use App\Models\Team;
use App\Models\TeamEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Scrive nel database i dati di un torneo letti da XFive.
 *
 * Punti importanti:
 *  - idempotente: rilanciarlo non crea duplicati;
 *  - il calendario può essere pubblicato a pezzi e poi RIGENERATO da XFive
 *    (id partita diversi): se l'id non si trova, la partita si riaggancia per
 *    giornata + squadre; le partite non più presenti e non giocate vengono
 *    annullate (mai cancellate), ma solo se la lettura ha restituito partite,
 *    così un'interruzione del sito non cancella nulla;
 *  - senza data né ora la partita resta "to_schedule" (provvisoria).
 */
final class CompetitionSyncer
{
    /**
     * @param  array{id:int,name:string,format:int,season:string,url?:?string,own?:bool}  $tournament
     * @param  array<int, array<string, mixed>>  $fixtures  output di PrintableCalendarParser
     * @return array<string, int>
     */
    public function sync(array $tournament, array $fixtures, bool $isCurrent): array
    {
        $ownClubId = (int) config('amir.own.club_id');
        $stats = ['fixtures' => count($fixtures), 'created' => 0, 'updated' => 0, 'cancelled' => 0, 'teams_created' => 0];

        DB::transaction(function () use (&$stats, $tournament, $fixtures, $isCurrent, $ownClubId) {
            $hasOwn = collect($fixtures)->contains(
                fn (array $f) => $f['home']['club_id'] === $ownClubId || $f['away']['club_id'] === $ownClubId
            ) || ($tournament['own'] ?? false);

            $overrides = (array) config('amir.competition_overrides.'.$tournament['id'], []);

            $competition = Competition::updateOrCreate(
                ['xfive_tournament_id' => $tournament['id']],
                [
                    'name' => CompetitionClassifier::cleanName($tournament['name']),
                    'season' => $tournament['season'],
                    'kind' => CompetitionClassifier::kind($tournament['name']),
                    'format' => $tournament['format'],
                    'is_current' => $isCurrent,
                    'is_excluded' => in_array($tournament['id'], array_map('intval', (array) config('amir.excluded_tournaments')), true),
                    'has_own_team' => $hasOwn,
                    'total_rounds' => $overrides['total_rounds'] ?? (collect($fixtures)->max('round') ?: null),
                    'xfive_url' => $tournament['url'] ?? null,
                    'synced_at' => now(),
                ],
            );

            $teamCache = [];
            $seen = [];

            foreach ($fixtures as $f) {
                $home = $this->resolveTeam($f['home'], $isCurrent, $teamCache, $stats);
                $away = $this->resolveTeam($f['away'], $isCurrent, $teamCache, $stats);

                $kickoff = KickoffParser::parse($f['kickoff_raw'], $tournament['season']);
                $played = $f['played'] && $f['home_score'] !== null && $f['away_score'] !== null;
                $status = $played ? Game::PLAYED : ($kickoff ? Game::SCHEDULED : Game::TO_SCHEDULE);

                $attributes = [
                    'xfive_match_id' => $f['xfive_match_id'],
                    'competition_id' => $competition->id,
                    'round' => $f['round'],
                    'round_label' => $f['round_label'],
                    'home_team_id' => $home->id,
                    'away_team_id' => $away->id,
                    'kickoff_at' => $kickoff,
                    'venue' => $f['venue'],
                    'home_score' => $played ? $f['home_score'] : null,
                    'away_score' => $played ? $f['away_score'] : null,
                    'status' => $status,
                    'last_seen_at' => now(),
                ];

                $game = Game::where('xfive_match_id', $f['xfive_match_id'])->first()
                    ?? Game::where('competition_id', $competition->id)
                        ->where('round', $f['round'])
                        ->where('home_team_id', $home->id)
                        ->where('away_team_id', $away->id)
                        ->first();

                if ($game) {
                    $game->fill($attributes)->save();
                    $stats['updated']++;
                } else {
                    $game = Game::create($attributes);
                    $stats['created']++;
                }

                $seen[$game->id] = true;

                if ($isCurrent) {
                    $this->syncEvent($game, $home, $away);
                }
            }

            if ($fixtures !== []) {
                // partite non più presenti nella lettura e non ancora giocate
                $stale = Game::where('competition_id', $competition->id)
                    ->whereIn('status', [Game::SCHEDULED, Game::TO_SCHEDULE, Game::POSTPONED])
                    ->get()
                    ->reject(fn (Game $g) => isset($seen[$g->id]));

                foreach ($stale as $game) {
                    $game->update(['status' => Game::CANCELLED]);
                    $stats['cancelled']++;
                }
            }

            $bounds = Game::where('competition_id', $competition->id)
                ->whereNotNull('kickoff_at')
                ->selectRaw('MIN(kickoff_at) as first_at, MAX(kickoff_at) as last_at')
                ->first();

            $competition->update([
                'starts_on' => $overrides['starts_on'] ?? ($bounds?->first_at ? Str::before($bounds->first_at, ' ') : $competition->starts_on),
                'ends_on' => $overrides['ends_on'] ?? ($bounds?->last_at ? Str::before($bounds->last_at, ' ') : $competition->ends_on),
            ]);

            $stats['competition_id'] = $competition->id;
        });

        return $stats;
    }

    /**
     * @param  array{club_id:int,name:string,badge_url:?string}  $data
     * @param  array<int, Team>  $cache
     */
    private function resolveTeam(array $data, bool $isCurrent, array &$cache, array &$stats): Team
    {
        $clubId = $data['club_id'];
        if (isset($cache[$clubId])) {
            return $cache[$clubId];
        }

        $team = Team::firstOrNew(['xfive_club_id' => $clubId]);
        $isNew = ! $team->exists;
        $isOwn = $clubId === (int) config('amir.own.club_id');

        // Il nome e lo stemma più recenti vincono; lo storico non li sovrascrive.
        if ($isNew || $isCurrent) {
            $team->name = $data['name'];
            if ($data['badge_url']) {
                $team->badge_url = $data['badge_url'];
            }
        }

        if ($isOwn) {
            $team->is_own = true;
            $team->short_name ??= config('amir.own.short_name');
            $team->format = config('amir.own.format');
            $team->kit1_color ??= config('amir.own.kit1_color');
            $team->kit2_color ??= config('amir.own.kit2_color');
        } else {
            $team->short_name ??= Str::limit($data['name'], 18, '');
            $kit = config('amir.kits.'.mb_strtolower($team->name));
            if (is_array($kit)) {
                $team->kit1_color ??= $kit[0];
                $team->kit2_color ??= $kit[1];
            }
        }

        $team->save();
        if ($isNew) {
            $stats['teams_created']++;
        }

        return $cache[$clubId] = $team;
    }

    /** Evento "partita" collegato alle nostre partite, per presenze e RSVP. */
    private function syncEvent(Game $game, Team $home, Team $away): void
    {
        $own = $home->is_own ? $home : ($away->is_own ? $away : null);
        if (! $own) {
            return;
        }

        $existing = TeamEvent::where('match_id', $game->id)->first();
        if (! $game->kickoff_at && ! $existing) {
            return;
        }

        $opponent = $home->is_own ? $away : $home;

        TeamEvent::updateOrCreate(
            ['match_id' => $game->id],
            [
                'team_id' => $own->id,
                'type' => 'match',
                'title' => $home->is_own
                    ? "{$own->short_name} - {$opponent->name}"
                    : "{$opponent->name} - {$own->short_name}",
                'starts_at' => $game->kickoff_at,
                'venue' => $game->venue,
            ],
        );
    }
}
