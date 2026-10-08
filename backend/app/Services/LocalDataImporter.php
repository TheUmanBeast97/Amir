<?php

namespace App\Services;

use App\Models\Charge;
use App\Models\Payment;
use App\Models\Player;
use App\Models\PlayerCharge;
use App\Models\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Porta nel gestionale online quello che hai inserito a mano nel gestionale sul computer: le info personali dei giocatori
 * (maglie, telefono, email, soprannome, note, scheda scout...) e i pagamenti (addebiti, quote, versamenti).
 *
 * È un'unione, non un ripristino: i giocatori si abbinano per nome a quelli che ci sono già online (importati da XFive) e
 * si completa solo quello che online è vuoto; Squad List, tesseramento e dati di XFive non si toccano. Addebiti, quote e
 * versamenti si aggiungono se mancano, e si ritrovano se ci sono già: rilanciarla non crea doppioni.
 */
final class LocalDataImporter
{
    /** I campi che si completano se online sono vuoti. Il resto (Squad List, tesseramento, link personale, foto...) è già giusto online. */
    private const FILL_IF_EMPTY = [
        'nickname', 'shirt_number', 'shirt_number_red', 'shirt_number_white', 'role', 'phone', 'email', 'birth_date', 'notes',
        'medical_cert_expires_on', 'nationality', 'xfive_person_id', 'xfive_profile', 'xfive_synced_at',
        'scout_text', 'scout_source', 'scout_generated_at',
    ];

    private const DATES = ['birth_date', 'medical_cert_expires_on'];

    private const METHODS = ['contanti', 'satispay', 'paypal', 'bonifico', 'altro'];

    public function __construct(private readonly DatabaseBackup $backup) {}

    /**
     * @return array<string, int>
     *
     * @throws InvalidArgumentException se il file non è un backup di questa app
     */
    public function import(Team $own, string $file): array
    {
        $tables = $this->backup->load($file);

        if (($tables['players'] ?? []) === []) {
            throw new InvalidArgumentException('Il file non contiene giocatori: non è un backup di AMIR Team Manager.');
        }

        $stats = [
            'players_matched' => 0, 'players_filled' => 0, 'fields_filled' => 0, 'players_ambiguous' => 0, 'players_not_found' => 0,
            'charges_created' => 0, 'player_charges_created' => 0, 'payments_created' => 0, 'payments_already_there' => 0, 'finance_skipped' => 0,
        ];

        DB::transaction(function () use ($own, $tables, &$stats) {
            $matched = $this->match($own, $tables['players'], $stats);
            $this->fillPlayers($matched, $stats);
            $this->importFinance($own, $tables, $matched, $stats);
        });

        return $stats;
    }

    /**
     * @param  array<int, array<string, mixed>>  $localPlayers
     * @param  array<string, int>  $stats
     * @return array<int, array{player: Player, local: array<string, mixed>}> id locale => giocatore online
     */
    private function match(Team $own, array $localPlayers, array &$stats): array
    {
        $byName = [];
        foreach (Player::where('team_id', $own->id)->orderBy('id')->get() as $player) {
            $byName[$this->nameKey($player->first_name.' '.$player->last_name)][] = $player;
        }

        // chi gioca ancora ha la precedenza su un omonimo che non gioca più
        usort($localPlayers, fn (array $a, array $b) => (int) ! empty($b['is_active']) <=> (int) ! empty($a['is_active']));

        $matched = [];
        $taken = [];

        foreach ($localPlayers as $local) {
            $candidates = $byName[$this->nameKey(($local['first_name'] ?? '').' '.($local['last_name'] ?? ''))] ?? [];

            if (count($candidates) > 1) {
                // omonimi online: li distingue solo la data di nascita, nel dubbio non si assegna a nessuno
                $birth = $this->day($local['birth_date'] ?? null);
                $candidates = array_values(array_filter($candidates, fn (Player $p) => $birth !== null && $p->birth_date?->toDateString() === $birth));
                if (count($candidates) !== 1) {
                    $stats['players_ambiguous']++;

                    continue;
                }
            }

            if ($candidates === []) {
                // gli ex giocatori del vecchio gestionale che online non ci sono non interessano: contano solo i giocatori attivi
                if (! empty($local['is_active'])) {
                    $stats['players_not_found']++;
                }

                continue;
            }

            if (isset($taken[$candidates[0]->id])) {
                $stats['players_ambiguous']++; // due schede locali per lo stesso giocatore online: vale la prima

                continue;
            }

            $taken[$candidates[0]->id] = true;
            $matched[(int) $local['id']] = ['player' => $candidates[0], 'local' => $local];
            $stats['players_matched']++;
        }

        return $matched;
    }

    /**
     * @param  array<int, array{player: Player, local: array<string, mixed>}>  $matched
     * @param  array<string, int>  $stats
     */
    private function fillPlayers(array $matched, array &$stats): void
    {
        foreach ($matched as ['player' => $player, 'local' => $local]) {
            $update = [];

            foreach (self::FILL_IF_EMPTY as $field) {
                $mine = $player->getAttribute($field);
                $theirs = $local[$field] ?? null;

                if (($mine !== null && $mine !== '' && $mine !== []) || $theirs === null || $theirs === '') {
                    continue;
                }

                if (in_array($field, self::DATES, true)) {
                    $theirs = $this->day($theirs);
                } elseif ($field === 'xfive_profile') {
                    $theirs = is_string($theirs) ? json_decode($theirs, true) : $theirs;
                } elseif ($field === 'xfive_person_id' && Player::where('xfive_person_id', $theirs)->where('id', '!=', $player->id)->exists()) {
                    continue; // quel profilo XFive è già di un altro giocatore
                }

                if ($theirs !== null && $theirs !== []) {
                    $update[$field] = $theirs;
                }
            }

            if ($update !== []) {
                $player->forceFill($update)->save();
                $stats['players_filled']++;
                $stats['fields_filled'] += count($update);
            }
        }
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $tables
     * @param  array<int, array{player: Player, local: array<string, mixed>}>  $matched
     * @param  array<string, int>  $stats
     */
    private function importFinance(Team $own, array $tables, array $matched, array &$stats): void
    {
        $chargeIds = [];
        foreach ($tables['charges'] ?? [] as $c) {
            $title = trim((string) ($c['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $amount = max(0, (int) ($c['amount_cents'] ?? 0));
            $kind = ($c['kind'] ?? '') !== '' ? (string) $c['kind'] : 'altro';
            $due = $this->day($c['due_on'] ?? null);

            $charge = Charge::where('team_id', $own->id)->where('title', $title)->where('amount_cents', $amount)
                ->when($due !== null, fn ($q) => $q->whereDate('due_on', $due), fn ($q) => $q->whereNull('due_on'))
                ->first();

            if (! $charge) {
                $charge = Charge::create([
                    'team_id' => $own->id, 'title' => $title, 'kind' => $kind, 'amount_cents' => $amount,
                    'due_on' => $due, 'season' => ($c['season'] ?? '') !== '' ? $c['season'] : null,
                ]);
                $stats['charges_created']++;
            }

            $chargeIds[(int) $c['id']] = $charge->id;
        }

        $quoteIds = [];
        foreach ($tables['player_charges'] ?? [] as $pc) {
            $chargeId = $chargeIds[(int) ($pc['charge_id'] ?? 0)] ?? null;
            $player = $matched[(int) ($pc['player_id'] ?? 0)]['player'] ?? null;

            if ($chargeId === null || $player === null) {
                $stats['finance_skipped']++;

                continue;
            }

            $quote = PlayerCharge::firstOrCreate(
                ['charge_id' => $chargeId, 'player_id' => $player->id],
                ['amount_cents' => max(0, (int) ($pc['amount_cents'] ?? 0))],
            );
            if ($quote->wasRecentlyCreated) {
                $stats['player_charges_created']++;
            }

            $quoteIds[(int) $pc['id']] = $quote->id;
        }

        foreach ($tables['payments'] ?? [] as $pay) {
            $quoteId = $quoteIds[(int) ($pay['player_charge_id'] ?? 0)] ?? null;
            if ($quoteId === null) {
                $stats['finance_skipped']++;

                continue;
            }

            $amount = max(0, (int) ($pay['amount_cents'] ?? 0));
            $date = $this->day($pay['paid_at'] ?? null) ?? now()->toDateString();
            $method = match (true) {
                ($pay['method'] ?? '') === '' => 'contanti', // come in tutta l'app quando non si dice altro
                in_array($pay['method'], self::METHODS, true) => $pay['method'],
                default => 'altro',
            };

            $already = Payment::where('player_charge_id', $quoteId)->where('amount_cents', $amount)->whereDate('paid_at', $date)
                ->where('method', $method)->exists();

            if ($already) {
                $stats['payments_already_there']++;

                continue;
            }

            Payment::create([
                'player_charge_id' => $quoteId, 'amount_cents' => $amount, 'method' => $method, 'paid_at' => $date,
                'note' => ($pay['note'] ?? '') !== '' ? $pay['note'] : null,
            ]);
            $stats['payments_created']++;
        }
    }

    /** «2026-11-20 00:00:00» diventa «2026-11-20»; ciò che non è una data diventa nullo. */
    private function day(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $m) ? $m[1] : null;
    }

    /** «De Luca Paolo» e «Paolo De Luca» danno la stessa chiave. */
    private function nameKey(string $name): string
    {
        $words = explode(' ', (string) Str::of($name)->ascii()->lower()->replaceMatches('/[^a-z0-9 ]+/', ' ')->squish());
        sort($words);

        return implode(' ', $words);
    }
}
