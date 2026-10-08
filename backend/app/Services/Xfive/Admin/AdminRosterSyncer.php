<?php

namespace App\Services\Xfive\Admin;

use App\Models\Player;
use App\Models\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Porta nei nostri giocatori quello che XFive sa di loro nell'area amministrazione (sola lettura su XFive):
 *  - Squad List e scadenza del certificato medico: vale XFive, è la fonte;
 *  - stato del tesseramento (tipo, data, importo, documenti caricati): salvato così com'è, solo da consultare;
 *  - data di nascita e ruolo: si completano se mancano; se la data di nascita è diversa non si tocca, si segnala.
 * Non crea giocatori e non ne cancella: chi non si abbina o è in più si conta e si lascia stare.
 */
final class AdminRosterSyncer
{
    public function __construct(
        private readonly XfiveAdminClient $client,
        private readonly AdminRosterParser $parser,
    ) {}

    /**
     * @return array<string, int>
     *
     * @throws XfiveAdminException
     */
    public function sync(Team $own): array
    {
        $rows = $this->parser->parse($this->client->rosterPage());

        if ($rows === []) {
            throw new XfiveAdminException('unexpected_page', 'La pagina della rosa non contiene nessun giocatore che riconosco: la struttura di XFive potrebbe essere cambiata. Non è stato modificato nulla.');
        }

        return $this->apply($own, $rows);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows  vedi AdminRosterParser::parse
     * @return array<string, int>
     */
    public function apply(Team $own, array $rows): array
    {
        $players = Player::where('team_id', $own->id)->orderBy('id')->get();

        $byAdminId = $players->filter(fn (Player $p) => $p->xfive_admin_id)->keyBy('xfive_admin_id');
        $byName = [];
        foreach ($players as $p) {
            $byName[$this->nameKey($p->first_name.' '.$p->last_name)][] = $p;
        }

        $stats = [
            'rows' => count($rows), 'matched' => 0, 'updated' => 0, 'new_links' => 0,
            'squad_list_changes' => 0, 'certificate_changes' => 0, 'birth_mismatch' => 0,
            'unmatched' => 0, 'ambiguous' => 0, 'missing_on_xfive' => 0,
        ];
        $seen = [];

        DB::transaction(function () use ($rows, $byAdminId, $byName, &$stats, &$seen) {
            foreach ($rows as $row) {
                $player = $byAdminId->get($row['admin_id']);

                if ($player === null) {
                    $found = $this->matchByName($row, $byName, $seen);
                    if ($found === 'ambiguous') {
                        $stats['ambiguous']++;

                        continue;
                    }
                    if ($found === null) {
                        $stats['unmatched']++;

                        continue;
                    }
                    $player = $found;
                    $stats['new_links']++;
                }

                $seen[$player->id] = true;
                $stats['matched']++;
                $stats = $this->update($player, $row, $stats);
            }
        });

        $stats['missing_on_xfive'] = $players->filter(fn (Player $p) => $p->is_active && ! isset($seen[$p->id]))->count();

        return $stats;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, int>  $stats
     * @return array<string, int>
     */
    private function update(Player $player, array $row, array $stats): array
    {
        $membership = $row['membership'];
        $changed = $player->xfive_admin_id !== $row['admin_id'];

        $update = [
            'xfive_admin_id' => $row['admin_id'],
            'xfive_membership' => $membership + ['documents' => $row['documents']],
            'xfive_admin_synced_at' => now(),
        ];

        // Squad List: solo se XFive dà un tesseramento a questo giocatore
        if ($membership['status'] !== 'none' && (bool) $player->in_squad_list !== $membership['is_squad_list']) {
            $update['in_squad_list'] = $membership['is_squad_list'];
            $stats['squad_list_changes']++;
            $changed = true;
        }

        // certificato: se XFive ha una data vale quella; se non ne ha, la nostra (magari presa dalla carta) resta
        $certificate = $row['certificate_expires_on'];
        if ($certificate !== null && $player->medical_cert_expires_on?->toDateString() !== $certificate) {
            $update['medical_cert_expires_on'] = $certificate;
            $stats['certificate_changes']++;
            $changed = true;
        }

        if ($row['birth_date'] !== null) {
            if ($player->birth_date === null) {
                $update['birth_date'] = $row['birth_date'];
                $changed = true;
            } elseif ($player->birth_date->toDateString() !== $row['birth_date']) {
                $stats['birth_mismatch']++;
            }
        }

        if (empty($player->role) && $row['role'] !== null) {
            $update['role'] = $row['role'];
            $changed = true;
        }

        $player->update($update);
        if ($changed) {
            $stats['updated']++;
        }

        return $stats;
    }

    /**
     * Abbina per nome (le parole in qualunque ordine: XFive scrive «Cognome Nome»), a parità con la data di nascita.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, array<int, Player>>  $byName
     * @param  array<int, true>  $taken  giocatori già abbinati in questa lettura
     */
    private function matchByName(array $row, array $byName, array $taken): Player|string|null
    {
        $candidates = array_values(array_filter(
            $byName[$this->nameKey($row['name'])] ?? [],
            fn (Player $p) => ! isset($taken[$p->id]) && ! $p->xfive_admin_id, // chi ha già un altro identificativo è un'altra persona
        ));

        if (count($candidates) === 1) {
            return $candidates[0];
        }
        if ($candidates === []) {
            return null;
        }

        $sameBirth = array_values(array_filter($candidates, fn (Player $p) => $row['birth_date'] !== null && $p->birth_date?->toDateString() === $row['birth_date']));

        return count($sameBirth) === 1 ? $sameBirth[0] : 'ambiguous';
    }

    /** «De Luca Paolo» e «Paolo De Luca» danno la stessa chiave. */
    private function nameKey(string $name): string
    {
        $words = explode(' ', (string) Str::of($name)->ascii()->lower()->replaceMatches('/[^a-z0-9 ]+/', ' ')->squish());
        sort($words);

        return implode(' ', $words);
    }
}
