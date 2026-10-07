<?php

namespace App\Services\Xfive;

use App\Models\SyncRun;
use App\Models\Team;
use App\Support\SafeError;
use RuntimeException;
use Throwable;

/**
 * Gli aggiornamenti da XFive che si lanciano dal sito o da un'esecuzione pianificata.
 *
 *   current  calendario e risultati della stagione in corso
 *   history  stagioni passate del club (storico)
 *   details  referti delle partite giocate (arbitro, distinta, marcatori, cartellini)
 *   media    stemmi e foto mancanti
 *   stats    statistiche per torneo dei nostri giocatori (stagione in corso)
 *
 * Sul server (Vercel) una richiesta può durare poco: gli ultimi tre lavorano entro un tempo massimo e, se c'è
 * ancora da fare, lo dicono in "remaining": basta lanciarli di nuovo.
 */
final class XfiveRoutine
{
    public const SCOPES = ['current', 'history', 'details', 'media', 'stats'];

    public function __construct(
        private readonly XfiveSyncService $calendar,
        private readonly MatchDetailsSyncer $details,
        private readonly PlayerProfileSyncer $players,
        private readonly BadgeSyncer $badges,
    ) {}

    /** $existing: una corsa già creata (es. dalla richiesta HTTP) da portare a termine. */
    public function run(string $scope, ?SyncRun $existing = null, float $budgetSeconds = 40.0): SyncRun
    {
        if ($scope === 'current' || $scope === 'history') {
            return $this->calendar->run($scope, $existing);
        }

        $run = $existing ?? SyncRun::create(['scope' => $scope, 'status' => 'running', 'started_at' => now(), 'stats' => []]);
        $deadline = microtime(true) + $budgetSeconds;
        $stats = [];
        $error = null;

        try {
            $own = Team::own() ?? throw new RuntimeException('Squadra non configurata.');
            $stats = match ($scope) {
                'details' => $this->details($own, $deadline),
                'media' => $this->media($own, $deadline),
                'stats' => $this->stats($own, $deadline),
                default => throw new RuntimeException("Aggiornamento sconosciuto: {$scope}."),
            };
            $status = 'ok';
        } catch (Throwable $e) {
            $status = 'error';
            $error = SafeError::describe($e); // l'esito resta leggibile nell'area staff: niente SQL con i valori
        }

        $run->update(['status' => $status, 'finished_at' => now(), 'stats' => $stats, 'error' => $error]);

        return $run->refresh();
    }

    /** @return array<string, int> */
    private function details(Team $own, float $deadline): array
    {
        // come l'aggiornamento automatico di sempre: partite senza dettagli e quelle degli ultimi giorni
        $games = $this->details->pendingGames($own, recent: true)->get();

        $queue = (function () use ($games, $deadline) {
            foreach ($games as $game) {
                if (microtime(true) > $deadline) {
                    return;
                }
                yield $game;
            }
        })();

        $r = $this->details->syncMany($queue, $own);

        return [
            'matches' => $r['matches'],
            'players_created' => $r['players_created'],
            'errors' => count($r['errors']),
            'remaining' => $this->details->pendingGames($own)->count(),
        ];
    }

    /** @return array<string, int> */
    private function media(Team $own, float $deadline): array
    {
        $badges = $this->badges->sync($this->badges->teams(), deadline: $deadline);
        $photos = $this->players->fillMissingPhotos($own, $deadline);

        return [
            'badges' => $badges['done'],
            'photos' => $photos['photos'],
            'remaining' => $badges['remaining'] + $photos['remaining'],
        ];
    }

    /** @return array<string, int> */
    private function stats(Team $own, float $deadline): array
    {
        $r = $this->players->syncStats($own, currentOnly: true, deadline: $deadline);

        return ['competitions' => $r['competitions'], 'rows' => $r['rows'], 'remaining' => $r['stopped'] ? 1 : 0];
    }
}
