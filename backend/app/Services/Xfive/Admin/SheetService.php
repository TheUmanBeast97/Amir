<?php

namespace App\Services\Xfive\Admin;

use App\Models\Game;
use App\Models\MatchCallup;
use App\Models\OfficialSheet;
use App\Models\OfficialSheetPlayer;
use App\Models\Player;
use App\Models\Team;
use App\Models\User;
use App\Models\XfiveSession;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * La distinta ufficiale di una partita: si apre su XFive con l'account di chi dello staff ha fatto «Collega XFive», si sceglie
 * chi gioca e con quale numero, e si invia. Ogni distinta inviata si ricorda qui, così il numero di maglia di ciascuno si ritrova
 * (e si suggerisce) anche per le partite dopo.
 *
 * XFive decide quando la distinta si può aprire (una finestra prima della gara): fuori da quella finestra si vedono comunque i
 * giocatori con i numeri suggeriti, ma non si invia. L'invio rilegge sempre il modulo da XFive, così gli id dei giocatori e i campi
 * nascosti sono quelli veri del momento.
 */
final class SheetService
{
    private const SCHEDULE_PATH = '/system/include/ajax/manager/manage_tournament/team_schedule.php';

    private const ROSTER_PATH = '/system/include/ajax/manager/manage_tournament/team.php';

    public function __construct(
        private readonly XfiveAccess $access,
        private readonly XfiveSessionStore $store,
        private readonly SheetParser $parser,
        private readonly AdminRosterParser $rosterParser,
        private readonly AdminRosterSyncer $rosterSyncer,
    ) {}

    /**
     * Assegna ai giocatori l'id che XFive dà loro nell'area amministrazione (la rosa), abbinando per nome: chi ce l'ha già non si tocca,
     * e non si cambia nient'altro. Serve il collegamento a XFive.
     *
     * @return array{rows: int, linked: int, already: int, unmatched: array<int, string>, ambiguous: array<int, string>, missing_on_xfive: array<int, string>}
     *
     * @throws XfiveAdminException
     */
    public function linkPlayers(User $user): array
    {
        $session = $this->store->current($user)
            ?? throw new XfiveAdminException('not_connected', 'Collega XFive per abbinare i giocatori.');

        $club = (int) config('amir.own.club_id');
        $jar = $this->access->jar($this->store->cookies($session));
        $json = $this->guard($user, fn () => $this->access->ajax($jar, self::ROSTER_PATH, $this->rosterParams($club), "/manage_tournament.php?tmid={$club}&sk=team", 'la rosa'));

        $rows = $this->rosterParser->parse((string) json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($rows === []) {
            throw new XfiveAdminException('unexpected_page', "XFive non ha restituito nessun giocatore che riconosco: l'elenco è vuoto oppure la struttura è cambiata. Non è stato modificato nulla.");
        }

        $this->store->touch($session, $jar->toArray());

        return $this->rosterSyncer->linkIds(Team::ownOrFail(), $rows);
    }

    /**
     * Il link al PDF della distinta su XFive (si scarica anche senza accesso, per questo non si mostra a chi non è dello staff).
     * Il gtid viene da una distinta inviata da qui oppure, se la distinta è stata compilata direttamente su XFive, da quando lo staff
     * ha aperto la scheda Distinta e il modulo risultava compilato. Null se per questa partita non risulta nessuna distinta.
     */
    public function pdfUrlFor(Game $game): ?string
    {
        if (! $game->xfive_match_id) {
            return null;
        }

        $gtid = OfficialSheet::where('match_id', $game->id)->whereNotNull('xfive_gtid')->latest('sent_at')->value('xfive_gtid')
            ?? $game->xfive_gtid;

        return $gtid ? $this->pdfUrl((int) $game->xfive_match_id, (int) $gtid) : null;
    }

    /**
     * Com'è la distinta per questa partita: giocatori, numeri suggeriti e storico, e se su XFive si può inviare adesso.
     *
     * @return array<string, mixed>
     *
     * @throws XfiveAdminException  solo per problemi veri (XFive non risponde, formato sconosciuto)
     */
    public function state(User $user, Game $game): array
    {
        $own = Team::ownOrFail();
        $roster = Player::where('team_id', $own->id)->where('is_active', true)->orderBy('last_name')->orderBy('first_name')->get();
        $calledUp = MatchCallup::where('match_id', $game->id)->pluck('player_id')->flip();
        $history = $this->history($roster->pluck('id')->all());

        $session = $this->store->current($user);
        $out = [
            'connected' => $session !== null,
            'open' => false,
            'reason' => $session === null ? 'Collega XFive per vedere e inviare la distinta.' : null,
            'match_id' => $game->id,
            'xfive_match_id' => $game->xfive_match_id,
            'pdf_url' => null,
            'note' => null,
            'players' => [],
            'last_sent' => $this->lastSent($game),
        ];

        $opened = null;
        if ($session !== null) {
            try {
                $opened = $this->openForm($user, $session, $game);
            } catch (XfiveAdminException $e) {
                if ($e->state === 'not_open') {
                    $out['reason'] = $e->getMessage();
                } elseif ($e->state === 'session_expired') {
                    $out['connected'] = false;
                    $out['reason'] = $e->getMessage();
                } else {
                    throw $e;
                }
            }
        }

        if ($opened !== null) {
            $form = $opened['form'];
            $anySelected = collect($form['players'])->contains(fn (array $p) => $p['selected']);
            $out['open'] = true;
            $out['note'] = $form['note'];
            $out['players'] = array_map(function (array $x) use ($roster, $calledUp, $history, $anySelected) {
                $player = $this->match($roster, $x);

                return $this->entry($player, $x, $history, $player !== null && isset($calledUp[$player->id]), $anySelected);
            }, $form['players']);

            if ($anySelected) {
                $out['pdf_url'] = $this->pdfUrl((int) $game->xfive_match_id, $opened['gtid']);
                // la distinta c'è su XFive (anche se fatta fuori da qui): ricordo la squadra, così il PDF resta raggiungibile dal sito
                if ((int) $game->xfive_gtid !== $opened['gtid']) {
                    $game->forceFill(['xfive_gtid' => $opened['gtid']])->saveQuietly();
                }
            }
        } else {
            $out['players'] = $roster->map(fn (Player $p) => $this->entry($p, null, $history, isset($calledUp[$p->id]), false))->values()->all();
        }

        return $out;
    }

    /**
     * Invia la distinta a XFive e la ricorda.
     *
     * @param  array<int, array{xfive_id:int, number:string}>  $selection  i giocatori scelti, con il numero di maglia
     * @return array<string, mixed>  lo stato aggiornato, più `message` (la risposta di XFive)
     *
     * @throws XfiveAdminException
     */
    public function save(User $user, Game $game, array $selection, ?string $note): array
    {
        $session = $this->store->current($user)
            ?? throw new XfiveAdminException('not_connected', 'Collega XFive per inviare la distinta.');

        $opened = $this->openForm($user, $session, $game);
        $form = $opened['form'];
        $known = collect($form['players'])->keyBy('xfive_id');

        $ids = [];
        $numbers = [];
        $used = [];
        foreach ($selection as $row) {
            $id = (int) ($row['xfive_id'] ?? 0);
            $number = strtoupper(trim((string) ($row['number'] ?? '')));
            $x = $known->get($id);

            if ($x === null) {
                throw new XfiveAdminException('invalid', 'Un giocatore scelto non è nell\'elenco di XFive: ricarica la distinta.');
            }
            if (! preg_match('/^[0-9A-Z]{1,3}$/', $number)) {
                throw new XfiveAdminException('invalid', "Il numero di maglia di {$x['name']} non è valido (da 1 a 3 caratteri, cifre o lettere).");
            }
            if (isset($used[$number])) {
                throw new XfiveAdminException('invalid', "Il numero {$number} è assegnato a due giocatori.");
            }
            if (in_array($id, $ids, true)) {
                throw new XfiveAdminException('invalid', "{$x['name']} è scelto due volte.");
            }

            $used[$number] = true;
            $ids[] = $id;
            $numbers[$id] = $number;
        }

        if ($ids === []) {
            throw new XfiveAdminException('invalid', 'Scegli almeno un giocatore.');
        }

        $payload = $form['hidden'];
        $payload['op'] = 3;
        $payload['mid'] = (int) $game->xfive_match_id;
        $payload['gtid'] = $opened['gtid'];
        $payload['players'] = $ids;
        foreach ($numbers as $id => $number) {
            $payload["number{$id}"] = $number;
        }
        $payload['note'] = $note ?? ($form['note'] ?? '');

        $club = (int) config('amir.own.club_id');
        $response = $this->guard($user, fn () => $this->access->ajax(
            $opened['jar'],
            self::SCHEDULE_PATH,
            $payload,
            "/manage_tournament.php?tmid={$club}&sk=team_schedule",
            'il salvataggio della distinta',
        ));
        $result = $this->parser->envelope($response);

        if ($result['errors'] !== null) {
            throw new XfiveAdminException('rejected', $result['errors']);
        }

        $this->store->touch($session, $opened['jar']->toArray());
        $this->remember($user, $game, $form['players'], $numbers, $note, $result['success'], $opened['gtid']);

        return $this->state($user, $game) + ['message' => $result['success'] ?? 'Distinta inviata a XFive.'];
    }

    /**
     * Per ogni giocatore attivo: l'ultimo numero e i numeri usati nelle distinte passate. Non serve l'accesso a XFive.
     *
     * @return array<int, array<string, mixed>>
     */
    public function numbers(): array
    {
        $own = Team::ownOrFail();
        $roster = Player::where('team_id', $own->id)->where('is_active', true)->orderBy('last_name')->orderBy('first_name')->get();
        $history = $this->history($roster->pluck('id')->all());

        return $roster->map(fn (Player $p) => [
            'player_id' => $p->id,
            'name' => $p->full_name,
            'shirt_number' => $p->shirt_number,
            'last_number' => $history[$p->id][0]['number'] ?? null,
            'numbers' => $history[$p->id] ?? [],
        ])->values()->all();
    }

    // ---------------------------------------------------------------------------------------------------------------------

    /**
     * Cerca la partita nell'elenco di XFive e apre il modulo della distinta.
     *
     * @return array{form: array<string, mixed>, gtid: int, jar: CookieJar}
     *
     * @throws XfiveAdminException  not_open se XFive non la rende disponibile adesso
     */
    private function openForm(User $user, XfiveSession $session, Game $game): array
    {
        if (! $game->xfive_match_id) {
            throw new XfiveAdminException('not_open', 'Questa partita non è su XFive (le amichevoli non hanno una distinta ufficiale).');
        }

        $jar = $this->access->jar($this->store->cookies($session));
        $club = (int) config('amir.own.club_id');
        $referer = "/manage_tournament.php?tmid={$club}&sk=team_schedule";
        $mid = (int) $game->xfive_match_id;

        $schedule = $this->guard($user, fn () => $this->access->ajax($jar, self::SCHEDULE_PATH, $this->scheduleParams($club), $referer, "l'elenco delle partite"));
        $gtid = $this->parser->gtidFor((string) json_encode($schedule, JSON_UNESCAPED_SLASHES), $mid);

        if ($gtid === null) {
            throw new XfiveAdminException('not_open', "La distinta non si può aprire adesso su XFive: la rende disponibile solo in una finestra prima della gara (fino a 5 minuti prima del calcio d'inizio).");
        }

        $opened = $this->parser->envelope($this->guard($user, fn () => $this->access->ajax($jar, self::SCHEDULE_PATH, ['op' => 2, 'mid' => $mid, 'gtid' => $gtid], $referer, 'il modulo della distinta')));

        if ($opened['errors'] !== null) {
            throw new XfiveAdminException('not_open', $opened['errors']);
        }
        if ($opened['html'] === null) {
            throw new XfiveAdminException('unexpected_page', 'XFive non ha restituito il modulo della distinta.');
        }

        $form = $this->parser->form($opened['html']);
        if ($form['players'] === []) {
            throw new XfiveAdminException('unexpected_page', 'XFive ha aperto una distinta senza giocatori: controlla la rosa su XFive.');
        }

        $this->store->touch($session, $jar->toArray());
        $this->linkSheetIds($form['players']);

        return ['form' => $form, 'gtid' => $gtid, 'jar' => $jar];
    }

    /**
     * Ricorda a ogni giocatore della rosa l'id che ha nel modulo della distinta (abbinato per nome e data di nascita): così
     * dalla volta dopo il riconoscimento è immediato. Non si assegna mai un id già di un altro.
     *
     * @param  array<int, array<string, mixed>>  $formPlayers
     */
    private function linkSheetIds(array $formPlayers): void
    {
        $roster = Player::where('team_id', Team::ownOrFail()->id)->get();
        $taken = $roster->pluck('xfive_sheet_id')->filter()->map(fn ($id) => (int) $id)->flip();

        foreach ($formPlayers as $x) {
            $player = $this->match($roster, $x);
            $id = (int) $x['xfive_id'];

            if ($player !== null && (int) $player->xfive_sheet_id !== $id && ! isset($taken[$id])) {
                $player->update(['xfive_sheet_id' => $id]);
                $taken[$id] = true;
            }
        }
    }

    /**
     * Gli stessi parametri che la tabella della rosa manda a XFive (tabella con paginazione lato server): tutto l'elenco, senza filtri.
     *
     * @return array<string, mixed>
     */
    private function rosterParams(int $club): array
    {
        $columns = [];
        foreach (range(0, 5) as $i) {
            $columns[] = ['data' => $i, 'name' => '', 'searchable' => 'true', 'orderable' => 'false', 'search' => ['value' => '', 'regex' => 'false']];
        }

        return [
            'draw' => 1,
            'columns' => $columns,
            'start' => 0,
            'length' => 200,
            'search' => ['value' => '', 'regex' => 'false'],
            'op' => 1,
            'tmid' => $club,
        ];
    }

    /**
     * Esegue una chiamata a XFive; se la sessione è scaduta la cancella (così l'app chiede di collegarsi di nuovo).
     *
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    private function guard(User $user, callable $call): mixed
    {
        try {
            return $call();
        } catch (XfiveAdminException $e) {
            if ($e->state === 'session_expired') {
                $this->store->close($user);
            }

            throw $e;
        }
    }

    /**
     * Gli stessi parametri che la tabella della pagina «Prossime partite» manda a XFive.
     *
     * @return array<string, mixed>
     */
    private function scheduleParams(int $club): array
    {
        $columns = [];
        foreach (range(0, 5) as $i) {
            $columns[] = ['data' => $i, 'name' => '', 'searchable' => 'true', 'orderable' => 'false', 'search' => ['value' => '', 'regex' => 'false']];
        }

        return [
            'draw' => 1,
            'columns' => $columns,
            'start' => 0,
            // XFive risponde 500 a length=-1 (provato): si chiedono al massimo 100 righe, e solo da ieri in poi come fa la pagina da sola
            'length' => 100,
            'search' => ['value' => '', 'regex' => 'false'],
            'op' => 1,
            'tmid' => $club,
            'date_from' => now('Europe/Rome')->subDay()->format('d/m/Y'),
            'date_to' => '',
        ];
    }

    private function pdfUrl(int $matchId, int $gtid): string
    {
        return rtrim((string) config('amir.xfive.base_url'), '/')."/pdfTeamsSchedule.php?mid={$matchId}&gtid={$gtid}";
    }

    /**
     * @param  array<string, mixed>|null  $x  la riga di XFive (null se la distinta non è aperta e si parte dalla nostra rosa)
     * @param  array<int, array<int, array<string, mixed>>>  $history
     * @return array<string, mixed>
     */
    private function entry(?Player $player, ?array $x, array $history, bool $calledUp, bool $xfiveHasSelection): array
    {
        $past = $player ? ($history[$player->id] ?? []) : [];
        $suggested = $past[0]['number'] ?? ($x['number'] ?? null) ?? $player?->shirt_number;

        return [
            'xfive_id' => $x['xfive_id'] ?? ($player?->xfive_sheet_id ? (int) $player->xfive_sheet_id : null),
            'player_id' => $player?->id,
            'name' => $player?->full_name ?? ($x['name'] ?? ''),
            'birth_date' => $x['birth_date'] ?? $player?->birth_date?->toDateString(),
            'called_up' => $calledUp,
            // se su XFive c'è già una distinta si parte da quella, altrimenti dai convocati
            'selected' => $x !== null && $xfiveHasSelection ? (bool) $x['selected'] : $calledUp,
            // la scelta che c'è già su XFive (diversa da «selected», che ripiega sui convocati) e il numero che XFive ha adesso
            'xfive_selected' => $x !== null && $x['selected'],
            'number' => $x['number'] ?? null,
            'suggested_number' => $suggested !== null && $suggested !== '' ? (string) $suggested : null,
            'history' => $past,
        ];
    }

    /**
     * Chi, fra i giocatori della rosa, è la riga di XFive: per l'id che ha nel modulo della distinta (già ricordato), altrimenti per
     * nome (nell'ordine che sia) e, se si conoscono, anche la data di nascita.
     *
     * @param  Collection<int, Player>  $roster
     * @param  array<string, mixed>  $x
     */
    private function match(Collection $roster, array $x): ?Player
    {
        $byId = $roster->first(fn (Player $p) => $p->xfive_sheet_id !== null && (int) $p->xfive_sheet_id === (int) $x['xfive_id']);
        if ($byId !== null) {
            return $byId;
        }

        $tokens = $this->tokens((string) $x['name']);
        if ($tokens === '') {
            return null;
        }

        $candidates = $roster->filter(function (Player $p) use ($tokens, $x) {
            if ($this->tokens($p->full_name) !== $tokens) {
                return false;
            }

            return $x['birth_date'] === null || $p->birth_date === null || $p->birth_date->toDateString() === $x['birth_date'];
        });

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    private function tokens(string $name): string
    {
        $words = preg_split('/\s+/', trim((string) preg_replace('/[^a-z ]/', '', strtolower(Str::ascii($name))))) ?: [];
        $words = array_values(array_filter($words));
        sort($words);

        return implode(' ', $words);
    }

    /**
     * I numeri usati da ciascun giocatore nelle distinte inviate, dal più recente: [{number, count, last_used_at}], al massimo 5.
     *
     * @param  array<int, int>  $playerIds
     * @return array<int, array<int, array{number:string, count:int, last_used_at:string}>>
     */
    private function history(array $playerIds): array
    {
        if ($playerIds === []) {
            return [];
        }

        $rows = OfficialSheetPlayer::with('sheet:id,match_id,sent_at')
            ->whereIn('player_id', $playerIds)
            ->get()
            ->filter(fn (OfficialSheetPlayer $r) => $r->sheet !== null)
            ->sortByDesc(fn (OfficialSheetPlayer $r) => $r->sheet->sent_at->getTimestamp());

        $out = [];
        foreach ($rows as $row) {
            $pid = (int) $row->player_id;
            $key = (string) $row->number;
            $out[$pid][$key] ??= ['number' => $key, 'count' => 0, 'last_used_at' => $row->sheet->sent_at->toIso8601String()];
            $out[$pid][$key]['count']++;
        }

        return array_map(fn (array $byNumber) => array_slice(array_values($byNumber), 0, 5), $out);
    }

    /** @return array{sent_at:string, players:int, message:?string}|null */
    private function lastSent(Game $game): ?array
    {
        $sheet = OfficialSheet::withCount('players')->where('match_id', $game->id)->latest('sent_at')->first();

        return $sheet ? [
            'sent_at' => $sheet->sent_at->toIso8601String(),
            'players' => (int) $sheet->players_count,
            'message' => $sheet->xfive_message,
        ] : null;
    }

    /**
     * Ricorda la distinta inviata: chi e con quale numero.
     *
     * @param  array<int, array<string, mixed>>  $xfivePlayers  le righe del modulo di XFive
     * @param  array<int, string>  $numbers  numero per id XFive, dei soli giocatori scelti
     */
    private function remember(User $user, Game $game, array $xfivePlayers, array $numbers, ?string $note, ?string $message, int $gtid): void
    {
        $own = Team::ownOrFail();
        $roster = Player::where('team_id', $own->id)->get();

        DB::transaction(function () use ($user, $game, $xfivePlayers, $numbers, $note, $message, $gtid, $roster) {
            $sheet = OfficialSheet::create([
                'match_id' => $game->id,
                'user_id' => $user->id,
                'xfive_gtid' => $gtid,
                'note' => $note !== null && trim($note) !== '' ? $note : null,
                'xfive_message' => $message !== null ? mb_substr($message, 0, 255) : null,
                'sent_at' => now(),
            ]);

            foreach ($xfivePlayers as $x) {
                if (! isset($numbers[$x['xfive_id']])) {
                    continue;
                }

                $player = $this->match($roster, $x);
                $sheet->players()->create([
                    'player_id' => $player?->id,
                    'xfive_admin_id' => $x['xfive_id'],
                    'name' => mb_substr($player?->full_name ?? $x['name'], 0, 120),
                    'number' => $numbers[$x['xfive_id']],
                ]);
            }
        });
    }
}
