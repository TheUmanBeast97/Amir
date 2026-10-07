<?php

namespace App\Services;

use App\Models\Player;
use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Importa la rosa da righe incollate o da CSV (anche da una copia dell'area
 * amministrazione di XFive). Le intestazioni possono essere in italiano o in
 * inglese; i campi vuoti non sovrascrivono quelli già presenti.
 */
final class PlayerImporter
{
    private const ALIASES = [
        'first_name' => ['nome', 'firstname', 'first_name'],
        'last_name' => ['cognome', 'lastname', 'last_name'],
        'full' => ['giocatore', 'nominativo', 'nomecompleto', 'fullname', 'full_name'],
        'birth_date' => ['datadinascita', 'data_nascita', 'nascita', 'birthdate', 'birth_date'],
        'role' => ['ruolo', 'role'],
        'shirt_number' => ['numero', 'maglia', 'numeromaglia', 'shirtnumber', 'shirt_number'],
        'shirt_number_red' => ['numerorosso', 'maglia_rossa', 'numeromagliarossa', 'rosso', 'shirt_number_red'],
        'shirt_number_white' => ['numerobianco', 'maglia_bianca', 'numeromagliabianca', 'bianco', 'shirt_number_white'],
        'phone' => ['telefono', 'cellulare', 'phone'],
        'email' => ['email', 'mail'],
        'nickname' => ['soprannome', 'nickname'],
        'notes' => ['note', 'notes'],
        'registration_status' => ['tesseramento', 'registrationstatus', 'registration_status'],
        'in_squad_list' => ['squadlist', 'insquadlist', 'in_squad_list'],
        'medical_cert_expires_on' => ['scadenzacertificato', 'certificato', 'medicalcertexpireson', 'medical_cert_expires_on'],
        'xfive_player_id' => ['idxfive', 'xfiveplayerid', 'xfive_player_id'],
    ];

    private const ROLES = ['portiere', 'difensore', 'centrocampista', 'attaccante', 'dirigente', 'allenatore'];

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{created:int, updated:int, skipped:int, errors: array<int,string>}
     */
    public function import(Team $team, array $rows): array
    {
        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];

        foreach ($rows as $i => $raw) {
            $row = $this->normalise((array) $raw);

            if (($row['first_name'] ?? '') === '' || ($row['last_name'] ?? '') === '') {
                $result['skipped']++;
                $result['errors'][] = 'Riga '.($i + 1).': nome o cognome mancante';

                continue;
            }

            $player = $this->find($team, $row);
            $attributes = array_filter($row, fn ($v) => $v !== null && $v !== '');

            if ($player) {
                $player->fill($attributes)->save();
                $result['updated']++;
            } else {
                Player::create($attributes + ['team_id' => $team->id]);
                $result['created']++;
            }
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function normalise(array $raw): array
    {
        $flat = [];
        foreach ($raw as $key => $value) {
            $flat[Str::of((string) $key)->ascii()->lower()->replace([' ', '-'], '')->toString()] = is_string($value) ? trim($value) : $value;
        }

        $pick = function (string $field) use ($flat) {
            foreach (self::ALIASES[$field] as $alias) {
                $k = str_replace('_', '', $alias);
                foreach ([$alias, $k] as $candidate) {
                    if (array_key_exists($candidate, $flat)) {
                        return $flat[$candidate];
                    }
                }
            }

            return null;
        };

        $first = (string) ($pick('first_name') ?? '');
        $last = (string) ($pick('last_name') ?? '');

        // "Cognome Nome" in una sola colonna, come nell'elenco di XFive
        if (($first === '' || $last === '') && ($full = (string) ($pick('full') ?? '')) !== '') {
            $parts = preg_split('/\s+/', $full, 2);
            $last = $parts[0] ?? '';
            $first = $parts[1] ?? '';
        }

        $role = Str::lower((string) ($pick('role') ?? ''));

        return [
            'first_name' => $first,
            'last_name' => $last,
            'nickname' => $pick('nickname'),
            'shirt_number' => $this->shirt($pick('shirt_number')),
            'shirt_number_red' => $this->shirt($pick('shirt_number_red')),
            'shirt_number_white' => $this->shirt($pick('shirt_number_white')),
            'role' => in_array($role, self::ROLES, true) ? $role : null,
            'phone' => $pick('phone'),
            'email' => $pick('email'),
            'birth_date' => $this->date($pick('birth_date')),
            'medical_cert_expires_on' => $this->date($pick('medical_cert_expires_on')),
            'registration_status' => $this->registration($pick('registration_status')),
            'in_squad_list' => $this->bool($pick('in_squad_list')),
            'xfive_player_id' => ($id = $pick('xfive_player_id')) !== null && $id !== '' ? (int) $id : null,
            'notes' => $pick('notes'),
        ];
    }

    private function find(Team $team, array $row): ?Player
    {
        if (! empty($row['xfive_player_id'])) {
            if ($p = Player::where('xfive_player_id', $row['xfive_player_id'])->first()) {
                return $p;
            }
        }

        $query = Player::where('team_id', $team->id)
            ->whereRaw('LOWER(first_name) = ?', [mb_strtolower($row['first_name'])])
            ->whereRaw('LOWER(last_name) = ?', [mb_strtolower($row['last_name'])]);

        if (! empty($row['birth_date'])) {
            $query->where(fn ($q) => $q->whereNull('birth_date')->orWhereDate('birth_date', $row['birth_date']));
        }

        return $query->first();
    }

    private function date(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;
        if ($value === null || $value === '') {
            return null;
        }

        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d'] as $format) {
            try {
                $d = Carbon::createFromFormat('!'.$format, (string) $value);
                if ($d && $d->format($format) === (string) $value) {
                    return $d->toDateString();
                }
            } catch (\Throwable) {
                // prova il formato successivo
            }
        }

        return null;
    }

    private function shirt(mixed $value): ?string
    {
        $value = is_string($value) ? mb_strtoupper(trim($value)) : (is_int($value) ? (string) $value : null);

        return ($value !== null && $value !== '') ? mb_substr($value, 0, 4) : null;
    }

    private function registration(mixed $value): ?string
    {
        $v = Str::of((string) $value)->ascii()->lower()->trim()->toString();

        return match (true) {
            $v === '' => null,
            str_contains($v, 'attesa'), $v === 'pending' => 'pending',
            str_contains($v, 'tesserat'), str_contains($v, 'approv'), $v === 'verde' => 'approved',
            default => 'none',
        };
    }

    private function bool(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        return in_array(Str::of((string) $value)->ascii()->lower()->trim()->toString(), ['si', '1', 'true', 'x', 'yes'], true);
    }
}
