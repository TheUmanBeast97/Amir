<?php

namespace App\Services\Xfive;

use App\Models\SyncRun;
use App\Models\Team;
use App\Services\Xfive\Admin\AdminRosterSyncer;
use App\Services\Xfive\Admin\XfiveAdminClient;
use App\Services\Zone\ZoneSync;
use App\Support\SafeError;
use Closure;
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
 * Mixed Zone (tabelle xf_*, vedi ZoneSync): zone-tournaments, zone-calendar, zone-standings, zone-stats, zone-teams,
 * zone-reports, zone-players, e «zone» che le fa in fila (quelle di ogni notte; il lunedì anche squadre e profili)
 * fermandosi al budget. Queste non hanno bisogno della nostra squadra.
 *
 * Sul server (Vercel) una richiesta può durare poco: details, media, stats, roster e tutte le zone-* lavorano entro un tempo
 * massimo e, se c'è ancora da fare, lo dicono in "remaining": basta lanciarli di nuovo. Mentre lavorano scrivono in
 * SyncRun.progress ({section, message, done, total}) l'ultimo passo, al massimo ogni 2 secondi: è la sala di controllo.
 */
final class XfiveRoutine
{
    public const SCOPES = ['current', 'history', 'details', 'media', 'stats', 'roster', 'admin', 'players', ...ZoneSync::SECTIONS, 'zone'];

    /** Ogni quanto, al massimo, l'avanzamento si scrive nel database. */
    private const PROGRESS_EVERY = 2.0;

    private float $progressSavedAt = 0.0;

    /** @var array{section: string, message: string, done: int, total: int}|null */
    private ?array $lastProgress = null;

    /** @var Closure(string, string, int, int):void|null chi vuole seguire l'avanzamento dal vivo (es. la console) */
    private ?Closure $listener = null;

    public function __construct(
        private readonly XfiveSyncService $calendar,
        private readonly MatchDetailsSyncer $details,
        private readonly PlayerProfileSyncer $players,
        private readonly BadgeSyncer $badges,
        private readonly AdminRosterSyncer $admin,
        private readonly XfiveAdminClient $adminClient,
        private readonly ZoneSync $zone,
    ) {}

    /** @param  Closure(string $section, string $message, int $done, int $total):void|null  $listener */
    public function onProgress(?Closure $listener): void
    {
        $this->listener = $listener;
    }

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
        $this->lastProgress = null;
        $this->progressSavedAt = 0.0;

        try {
            if ($scope === 'zone' || in_array($scope, ZoneSync::SECTIONS, true)) {
                $stats = $this->zone($scope, $deadline, $run);
            } else {
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
            }
            $status = 'ok';
        } catch (Throwable $e) {
            $status = 'error';
            $error = SafeError::describe($e); // l'esito resta leggibile nell'area staff: niente SQL con i valori
        }

        $run->update(['status' => $status, 'finished_at' => now(), 'stats' => $stats, 'progress' => $this->lastProgress, 'error' => $error]);

        return $run->refresh();
    }

    /**
     * Le sezioni della Mixed Zone: una sola, oppure («zone») quelle di ogni notte in fila e il lunedì anche squadre e
     * profili. Ogni sezione si ferma da sola alla scadenza e dice quanto le resta: le sezioni dopo, chiamate a tempo
     * scaduto, non chiedono nulla a XFive e contano come «da fare». I conteggi si sommano (le chiavi sono distinte).
     *
     * @return array<string, int>
     */
    private function zone(string $scope, float $deadline, SyncRun $run): array
    {
        $sections = $scope === 'zone' ? ZoneSync::nightly(now()->isMonday()) : [$scope];
        $totals = [];
        $remaining = 0;
        $done = 0;

        foreach ($sections as $section) {
            $r = $this->zone->section($section, $deadline, $this->progressWriter($run, $section));
            $left = (int) ($r['remaining'] ?? 0);
            $remaining += $left;
            unset($r['remaining']);
            if ($left === 0) {
                $done++; // il giro di questa sezione è completo
            }
            foreach ($r as $k => $v) {
                $totals[$k] = ($totals[$k] ?? 0) + (int) $v;
            }
        }

        return $totals + ($scope === 'zone' ? ['sections' => $done] : []) + ['remaining' => $remaining];
    }

    /** La penna dell'avanzamento: scrive in SyncRun.progress al massimo ogni 2 s e avvisa chi ascolta. */
    private function progressWriter(SyncRun $run, string $section): Closure
    {
        return function (string $message, int $done, int $total) use ($run, $section): void {
            $this->lastProgress = ['section' => $section, 'message' => $message, 'done' => $done, 'total' => $total];
            if ($this->listener) {
                ($this->listener)($section, $message, $done, $total);
            }
            $now = microtime(true);
            if ($now - $this->progressSavedAt >= self::PROGRESS_EVERY) {
                $this->progressSavedAt = $now;
                $run->update(['progress' => $this->lastProgress]);
            }
        };
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
