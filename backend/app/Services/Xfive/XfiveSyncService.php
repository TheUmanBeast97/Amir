<?php

namespace App\Services\Xfive;

use App\Models\SyncRun;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestra l'aggiornamento da XFive.
 *   scope "current": stagione in corso (calendario provvisorio, risultati);
 *   scope "history": stagioni passate del club (storico).
 */
final class XfiveSyncService
{
    public function __construct(
        private readonly XfiveClient $client,
        private readonly PrintableCalendarParser $calendar,
        private readonly ClubHistoryParser $history,
        private readonly CompetitionSyncer $syncer,
    ) {}

    /** $existing: un SyncRun già creato (es. dalla richiesta HTTP) da portare a termine. */
    public function run(string $scope, ?SyncRun $existing = null): SyncRun
    {
        $run = $existing ?? SyncRun::create([
            'scope' => $scope,
            'status' => 'running',
            'started_at' => now(),
            'stats' => [],
        ]);

        $stats = ['competitions' => 0, 'fixtures' => 0, 'created' => 0, 'updated' => 0, 'cancelled' => 0, 'errors' => 0];
        $error = null;

        try {
            $currentLabel = (string) config('amir.xfive.current_season');

            foreach ((array) config('amir.xfive.seasons') as $sid => $label) {
                $isCurrent = $label === $currentLabel;
                if (($scope === 'current') !== $isCurrent) {
                    continue;
                }

                foreach ($this->tournamentsFor((int) $sid, (string) $label, $isCurrent) as $tournament) {
                    try {
                        $fixtures = $this->calendar->parse($this->client->printableCalendar($tournament['id']));
                        $result = $this->syncer->sync($tournament, $fixtures, $isCurrent);

                        $stats['competitions']++;
                        foreach (['fixtures', 'created', 'updated', 'cancelled'] as $key) {
                            $stats[$key] += $result[$key];
                        }
                    } catch (Throwable $e) {
                        $stats['errors']++;
                        $error = "Torneo {$tournament['id']}: ".$e->getMessage();
                        Log::warning('Sync XFive: '.$error);
                    }
                }
            }

            $status = ($stats['errors'] > 0 && $stats['competitions'] === 0) ? 'error' : 'ok';
        } catch (Throwable $e) {
            $status = 'error';
            $error = $e->getMessage();
            Log::error('Sync XFive fallita: '.$error);
        }

        $run->update([
            'status' => $status,
            'finished_at' => now(),
            'stats' => $stats,
            'error' => $error,
        ]);

        return $run->refresh();
    }

    /**
     * Tornei disputati dal nostro club in una stagione. Per quella corrente si
     * aggiungono anche quelli indicati in configurazione.
     *
     * @return array<int, array{id:int,slug:string,name:string,format:int,url:?string,season:string,own:bool}>
     */
    private function tournamentsFor(int $seasonId, string $season, bool $isCurrent): array
    {
        $html = $this->client->clubSeasonTournaments((int) config('amir.own.club_id'), $seasonId);

        $list = array_map(
            fn (array $t) => $t + ['season' => $season, 'own' => true],
            $this->history->parse($html),
        );

        if ($isCurrent) {
            $known = array_column($list, 'id');
            foreach ((array) config('amir.xfive.current_tournaments') as $id) {
                if (! in_array((int) $id, $known, true)) {
                    $list[] = [
                        'id' => (int) $id,
                        'slug' => '',
                        'name' => "Torneo {$id}",
                        'format' => (int) config('amir.own.format'),
                        'url' => null,
                        'season' => $season,
                        'own' => false,
                    ];
                }
            }
        }

        return $list;
    }
}
