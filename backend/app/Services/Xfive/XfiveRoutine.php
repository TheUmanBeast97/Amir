<?php

namespace App\Services\Xfive;

use App\Models\SyncRun;
use App\Models\Team;
use App\Services\Xfive\Admin\AdminRosterSyncer;
use App\Services\Xfive\Admin\XfiveAdminClient;
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
 *   stats    statistiche per torneo dei nostri giocatori (stagione in corso) e, a turno, profili e foto di chi non si rilegge da una settimana
 *   roster   profili e foto di tutta la rosa, riletti da XFive anche se c'erano già (pulsante «Aggiorna rosa da XFive»)
 *   admin    rosa, tesseramenti, certificati e Squad List dall'area amministrazione (serve l'accesso con il tuo account, spento di serie)
 *   players  come admin, ma crea anche i giocatori che su XFive ci sono e da noi no (pulsante «Importa giocatori da XFive»)
 *
 * Sul server (Vercel) una richiesta può durare poco: details, media, stats e roster lavorano entro un tempo massimo e, se c'è
 * ancora da fare, lo dicono in "remaining": basta lanciarli di nuovo.
 */
final class XfiveRoutine
{
    public const SCOPES = ['current', 'history', 'details', 'media', 'stats', 'roster', 'admin', 'players'];

    public function __construct(
        private readonly XfiveSyncService $calendar,
        private readonly MatchDetailsSyncer $details,
        private readonly PlayerProfileSyncer $players,
        private readonly BadgeSyncer $badges,
        private readonly AdminRosterSyncer $admin,
        private readonly XfiveAdminClient $adminClient,
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
                'roster' => $this->roster($own, $deadline),
                'admin' => $this->admin($own, create: false),
                'players' => $this->admin($own, create: true),
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

    /**
     * Rosa, tesseramenti, certificati e Squad List dall'area amministrazione di XFive. Solo se qualcuno l'ha accesa
     * (XFIVE_ADMIN_ENABLED e le credenziali): altrimenti non fa nulla, senza contattare XFive.
     *
     * @return array<string, int>
     */
    private function admin(Team $own, bool $create): array
    {
        if (! $this->adminClient->enabled()) {
            return ['disabled' => 1];
        }

        return $this->admin->sync($own, $create);
    }

    /**
     * Statistiche della stagione e poi, col tempo che resta, profili e foto dei giocatori che non si rileggono da una
     * settimana: così ogni notte qualcuno viene rinfrescato e in una settimana tutta la rosa (senza un'altra pianificazione).
     *
     * @return array<string, int>
     */
    private function stats(Team $own, float $deadline): array
    {
        $r = $this->players->syncStats($own, currentOnly: true, deadline: $deadline);
        $roster = $this->players->refreshRoster($own, $deadline, staleOnly: true);

        return [
            'competitions' => $r['competitions'],
            'rows' => $r['rows'],
            'refreshed' => $roster['players'],
            'photos' => $roster['photos'],
            'remaining' => ($r['stopped'] ? 1 : 0) + $roster['remaining'],
        ];
    }

    /** Tutta la rosa attiva riletta da XFive: profili, foto (non quelle caricate dallo staff) e statistiche della stagione. */
    private function roster(Team $own, float $deadline): array
    {
        $r = $this->players->refreshRoster($own, $deadline);
        $stats = $r['remaining'] === 0 ? $this->players->syncStats($own, currentOnly: true, deadline: $deadline) : ['rows' => 0, 'stopped' => false];

        return [
            'players' => $r['players'],
            'photos' => $r['photos'],
            'not_found' => $r['not_found'],
            'ambiguous' => $r['ambiguous'],
            'rows' => $stats['rows'],
            'remaining' => $r['remaining'] + ($stats['stopped'] ? 1 : 0),
        ];
    }
}
