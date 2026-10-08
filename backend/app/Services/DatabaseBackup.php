<?php

namespace App\Services;

use App\Support\SafeError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Copia di sicurezza e ripristino di tutti i dati, indipendenti dal database in uso (SQLite in locale, Postgres online).
 *
 * Il backup è un file JSON compresso con una tabella dopo l'altra. Per ripristinare si accetta anche il vecchio
 * file SQLite del gestionale locale (database.sqlite): è il modo per portare online i dati di sempre.
 *
 * Nel backup non entrano: lo stato di servizio (token di accesso, sessioni, cache, code), l'elenco delle migrazioni e le
 * immagini (si riscaricano da XFive). Il ripristino non esegue mai SQL preso dal file: usa solo nomi di tabelle e colonne
 * che esistono già nel database e passa i valori come parametri, tutto in una sola transazione (se qualcosa non va,
 * i dati di prima restano com'erano).
 */
class DatabaseBackup
{
    public const FORMAT = 'amir-backup';

    public const VERSION = 1;

    /** Quanto può pesare un backup una volta decompresso (protezione da file «bomba»). */
    private const MAX_UNPACKED_BYTES = 64 * 1024 * 1024;

    /** Senza queste tabelle un file non è un backup di questa app. */
    private const REQUIRED_TABLES = ['users', 'players', 'matches', 'teams', 'competitions'];

    /**
     * Stato di servizio, non dati: sessioni e token di accesso, cache, code. Non si salvano e non si ripristinano:
     * i token di un backup potrebbero appartenere a utenti diversi da quelli di adesso, e cache e code contengono oggetti PHP
     * serializzati che l'app rilegge, quindi un file costruito ad arte non deve poterli portare dentro.
     * I nomi si leggono dalla configurazione: se qualcuno li cambia (es. DB_CACHE_TABLE) la pulizia segue.
     *
     * @return array<int, string>
     */
    public function runtimeTables(): array
    {
        return array_values(array_unique(array_filter([
            // i nomi di serie (se la configurazione non li dice, valgono questi)
            'personal_access_tokens', 'sessions', 'password_reset_tokens', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
            config('session.table', 'sessions'),
            config('auth.passwords.'.config('auth.defaults.passwords').'.table', 'password_reset_tokens'),
            config('cache.stores.database.table', 'cache'),
            config('cache.stores.database.lock_table', 'cache_locks'),
            config('queue.connections.database.table', 'jobs'),
            config('queue.batching.table', 'job_batches'),
            config('queue.failed.table', 'failed_jobs'),
        ], 'is_string')));
    }

    /** Tabelle che il backup non tocca: lo stato di servizio più migrazioni e immagini. */
    private function skippedTables(): array
    {
        return array_map('strtolower', [...$this->runtimeTables(), 'migrations', 'media_files']);
    }

    // ------------------------------------------------------------------ esportazione

    /** Il backup di tutti i dati, già compresso (contenuto di un file .json.gz). */
    public function export(): string
    {
        $tables = [];

        foreach ($this->dataTables() as $table) {
            $columns = Schema::getColumnListing($table);
            $query = DB::table($table);
            if (in_array('id', $columns, true)) {
                $query->orderBy('id');
            }

            $rows = [];
            foreach ($query->get() as $row) {
                $row = (array) $row;
                $rows[] = array_map(fn (string $c) => $row[$c] ?? null, $columns);
            }

            $tables[$table] = ['columns' => $columns, 'rows' => $rows];
        }

        $json = json_encode([
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'created_at' => now()->toIso8601String(),
            'migrations' => DB::table('migrations')->pluck('migration')->all(),
            'tables' => $tables,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);

        $packed = gzencode($json, 6);
        if ($packed === false) {
            throw new RuntimeException('Non riesco a comprimere la copia.');
        }

        return $packed;
    }

    // ------------------------------------------------------------------ ripristino

    /**
     * Sostituisce i dati con quelli del file indicato (backup JSON, compresso o no, oppure database SQLite del gestionale).
     * Chi era collegato dovrà accedere di nuovo: i token di accesso si cancellano.
     *
     * @throws InvalidArgumentException se il file non è un backup di questa app o non si può applicare
     */
    public function restore(string $uploaded): void
    {
        $backup = $this->read($uploaded);
        $order = $this->checkAgainstSchema($backup);

        $stage = 'avvio';

        try {
            DB::transaction(function () use ($backup, $order, &$stage) {
                // prima i figli, poi i padri: nessuna regola di collegamento viene violata
                foreach (array_reverse($order) as $table) {
                    $stage = "pulizia di {$table}";
                    DB::table($table)->delete();
                }
                foreach ($order as $table) {
                    if (isset($backup['tables'][$table])) {
                        $stage = "scrittura di {$table}";
                        $this->insert($table, $backup['tables'][$table]);
                    }
                }
                $stage = 'pulizia dello stato di servizio';
                foreach ($this->runtimeTablesPresent() as $table) {
                    DB::table($table)->delete();
                }
                $stage = 'riallineamento dei contatori';
                $this->resyncSequences($order);
            });
        } catch (Throwable $e) {
            // mai il messaggio intero: per un errore del database contiene l'SQL con i valori (email, impronte delle password)
            Log::warning("Ripristino non riuscito ({$stage}): ".SafeError::describe($e));

            throw new InvalidArgumentException('Il backup non si può applicare: i dati non sono coerenti con questa versione. Non è stato cambiato nulla.');
        }
    }

    /**
     * Le tabelle di un backup (JSON, compresso o no, oppure database SQLite del gestionale) senza applicarlo: nome della tabella
     * in minuscolo => righe come liste associative. Serve a chi vuole solo prenderne una parte (vedi LocalDataImporter).
     *
     * @return array<string, array<int, array<string, mixed>>>
     *
     * @throws InvalidArgumentException se il file non è un backup di questa app
     */
    public function load(string $file): array
    {
        $backup = $this->read($file);
        $tables = [];

        foreach ($backup['tables'] as $name => $table) {
            $rows = [];
            foreach ($table['rows'] as $row) {
                $rows[] = array_combine($table['columns'], $row);
            }
            $tables[strtolower((string) $name)] = $rows;
        }

        return $tables;
    }

    // ------------------------------------------------------------------ lettura dei file

    /** @return array{tables: array<string, array{columns: array<int,string>, rows: array<int, array<int,mixed>>}>, migrations: array<int,string>|null} */
    private function read(string $file): array
    {
        $head = (string) file_get_contents($file, false, null, 0, 16);

        if (str_starts_with($head, "SQLite format 3\0")) {
            return $this->readSqlite($file);
        }

        $raw = (string) file_get_contents($file, false, null, 0, self::MAX_UNPACKED_BYTES + 1);
        if (str_starts_with($raw, "\x1f\x8b")) {
            $raw = @gzdecode($raw, self::MAX_UNPACKED_BYTES + 1);
            if ($raw === false) {
                throw new InvalidArgumentException('Il file compresso è danneggiato.');
            }
        }
        if (strlen($raw) > self::MAX_UNPACKED_BYTES) {
            throw new InvalidArgumentException('Il file è troppo grande per essere un backup di AMIR Team Manager.');
        }

        try {
            $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidArgumentException('Il file non è un backup di AMIR Team Manager.');
        }

        if (! is_array($data) || ($data['format'] ?? null) !== self::FORMAT || ! is_array($data['tables'] ?? null)) {
            throw new InvalidArgumentException('Il file non è un backup di AMIR Team Manager.');
        }
        if ((int) ($data['version'] ?? 0) > self::VERSION) {
            throw new InvalidArgumentException('Il backup viene da una versione più recente: aggiorna prima il server.');
        }

        $tables = [];
        foreach ($data['tables'] as $name => $table) {
            if (! is_string($name) || ! is_array($table) || ! is_array($table['columns'] ?? null) || ! is_array($table['rows'] ?? null)) {
                throw new InvalidArgumentException('Il backup è fatto in modo che non riconosco.');
            }
            $columns = array_values($table['columns']);
            foreach ($columns as $c) {
                if (! is_string($c)) {
                    throw new InvalidArgumentException('Il backup è fatto in modo che non riconosco.');
                }
            }
            $rows = [];
            foreach ($table['rows'] as $row) {
                if (! is_array($row) || count($row) !== count($columns)) {
                    throw new InvalidArgumentException("Il backup è danneggiato (tabella {$name}).");
                }
                foreach ($row as $cell) {
                    if ($cell !== null && ! is_scalar($cell)) {
                        throw new InvalidArgumentException("Il backup è danneggiato (tabella {$name}).");
                    }
                }
                $rows[] = array_values($row);
            }
            $tables[$name] = ['columns' => $columns, 'rows' => $rows];
        }

        $migrations = is_array($data['migrations'] ?? null) ? array_values(array_filter($data['migrations'], 'is_string')) : null;

        return ['tables' => $tables, 'migrations' => $migrations];
    }

    /** Il database SQLite del gestionale locale, letto in sola lettura: se ne prendono solo le tabelle e le colonne che conosciamo. */
    private function readSqlite(string $file): array
    {
        try {
            $pdo = new PDO('sqlite:'.$file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('PRAGMA query_only = ON');

            $known = $this->schemaTables();
            $tables = [];
            $unknown = [];

            $names = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($names as $name) {
                $key = strtolower((string) $name);
                if (in_array($key, $this->skippedTables(), true)) {
                    continue;
                }
                if (! isset($known[$key])) {
                    $unknown[] = (string) $name;

                    continue;
                }

                // i nomi che finiscono nella query sono sempre quelli del NOSTRO schema, mai quelli del file
                $own = $known[$key];
                $inFile = array_map('strtolower', array_column($pdo->query('PRAGMA table_info("'.str_replace('"', '""', (string) $name).'")')->fetchAll(PDO::FETCH_ASSOC), 'name'));
                $columns = array_values(array_filter(Schema::getColumnListing($own), fn (string $c) => in_array(strtolower($c), $inFile, true)));
                if ($columns === []) {
                    continue;
                }

                $select = implode(', ', array_map(fn (string $c) => '"'.str_replace('"', '""', $c).'"', $columns));
                $tables[$own] = [
                    'columns' => $columns,
                    'rows' => $pdo->query('SELECT '.$select.' FROM "'.str_replace('"', '""', (string) $name).'"')->fetchAll(PDO::FETCH_NUM),
                ];
            }

            if ($unknown !== []) {
                throw new InvalidArgumentException('Il file contiene tabelle che questa versione non conosce ('.implode(', ', $unknown).'): viene da una versione più recente? Aggiorna prima il server.');
            }

            $migrations = null;
            if (in_array('migrations', array_map('strtolower', $names), true)) {
                $migrations = $pdo->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
            }
        } catch (\PDOException) {
            throw new InvalidArgumentException('Il file non si può leggere come database.');
        }

        return ['tables' => $tables, 'migrations' => $migrations];
    }

    // ------------------------------------------------------------------ controlli

    /**
     * Controlla il backup contro il database in uso e restituisce l'ordine in cui scrivere le tabelle (i padri prima dei figli).
     *
     * @return array<int, string>
     */
    private function checkAgainstSchema(array &$backup): array
    {
        $known = $this->schemaTables();
        $normalized = [];
        $unknown = [];

        foreach ($backup['tables'] as $name => $table) {
            $key = strtolower((string) $name);
            if (in_array($key, $this->skippedTables(), true)) {
                continue; // stato di servizio e immagini: mai dal file
            }
            if (! isset($known[$key])) {
                $unknown[] = (string) $name;

                continue;
            }
            $normalized[$known[$key]] = $table;
        }

        if ($unknown !== []) {
            throw new InvalidArgumentException('Il file contiene tabelle che questa versione non conosce ('.implode(', ', $unknown).'): viene da una versione più recente? Aggiorna prima il server.');
        }

        $missing = array_diff(self::REQUIRED_TABLES, array_keys($normalized));
        if ($missing !== []) {
            throw new InvalidArgumentException('Non è un backup di AMIR Team Manager (mancano: '.implode(', ', $missing).').');
        }

        if (count($normalized['users']['rows']) === 0) {
            throw new InvalidArgumentException('Il backup non contiene nessun utente: dopo il ripristino non potresti più accedere.');
        }

        if (is_array($backup['migrations'])) {
            $installed = DB::table('migrations')->pluck('migration')->all();
            $newer = array_diff($backup['migrations'], $installed);
            if ($newer !== []) {
                throw new InvalidArgumentException('Il backup viene da una versione più recente dell\'app: aggiorna prima il server.');
            }
        }

        $backup['tables'] = $normalized;

        return $this->dependencyOrder(array_values($known));
    }

    /** Nomi delle tabelle di dati del database in uso, minuscolo => nome vero. @return array<string, string> */
    private function schemaTables(): array
    {
        $map = [];
        foreach ($this->dataTables() as $table) {
            $map[strtolower($table)] = $table;
        }

        return $map;
    }

    /** Tabelle di dati del database in uso (senza stato di servizio, migrazioni e immagini). @return array<int, string> */
    private function dataTables(): array
    {
        $names = array_map(fn (array $t) => (string) $t['name'], Schema::getTables());

        return array_values(array_filter($names, fn (string $n) => ! str_starts_with($n, 'sqlite_') && ! in_array(strtolower($n), $this->skippedTables(), true)));
    }

    /** Tabelle di servizio che esistono davvero nel database in uso. @return array<int, string> */
    private function runtimeTablesPresent(): array
    {
        $existing = array_map(fn (array $t) => strtolower((string) $t['name']), Schema::getTables());

        return array_values(array_filter($this->runtimeTables(), fn (string $t) => in_array(strtolower($t), $existing, true)));
    }

    /**
     * Ordina le tabelle perché ogni tabella venga dopo quelle a cui rimanda (chiavi esterne). Se ci fosse un giro chiuso
     * le tabelle rimaste si scrivono in fondo, nell'ordine in cui sono.
     *
     * @param  array<int, string>  $tables
     * @return array<int, string>
     */
    private function dependencyOrder(array $tables): array
    {
        $lower = array_combine(array_map('strtolower', $tables), $tables);
        $needs = [];
        foreach ($tables as $table) {
            $needs[$table] = [];
            foreach (Schema::getForeignKeys($table) as $fk) {
                $target = $lower[strtolower((string) $fk['foreign_table'])] ?? null;
                if ($target !== null && $target !== $table) {
                    $needs[$table][$target] = true;
                }
            }
        }

        $ordered = [];
        $remaining = $tables;
        while ($remaining !== []) {
            $ready = array_values(array_filter($remaining, fn (string $t) => array_diff_key($needs[$t], array_flip($ordered)) === []));
            if ($ready === []) {
                $ready = [$remaining[array_key_first($remaining)]];
            }
            foreach ($ready as $table) {
                $ordered[] = $table;
            }
            $remaining = array_values(array_diff($remaining, $ready));
        }

        return $ordered;
    }

    // ------------------------------------------------------------------ scrittura

    /** @param array{columns: array<int,string>, rows: array<int, array<int,mixed>>} $table */
    private function insert(string $name, array $table): void
    {
        $columns = Schema::getColumns($name);
        $ownByLower = [];
        $isBool = [];
        foreach ($columns as $c) {
            $ownByLower[strtolower((string) $c['name'])] = (string) $c['name'];
            $isBool[(string) $c['name']] = in_array(strtolower((string) ($c['type_name'] ?? '')), ['bool', 'boolean'], true) || strtolower((string) ($c['type'] ?? '')) === 'tinyint(1)';
        }

        // si usano solo le colonne che il database in uso ha; le altre del file (di una versione diversa) si lasciano da parte
        $use = [];
        foreach ($table['columns'] as $index => $column) {
            if (isset($ownByLower[strtolower($column)])) {
                $use[$index] = $ownByLower[strtolower($column)];
            }
        }
        if ($use === []) {
            return;
        }

        $perStatement = max(1, intdiv(800, count($use)));
        foreach (array_chunk($table['rows'], $perStatement) as $chunk) {
            $rows = [];
            foreach ($chunk as $row) {
                $assoc = [];
                foreach ($use as $index => $column) {
                    $value = $row[$index];
                    $assoc[$column] = ($isBool[$column] && $value !== null) ? (bool) $value : $value;
                }
                $rows[] = $assoc;
            }
            DB::table($name)->insert($rows);
        }
    }

    /** Dopo aver scritto righe con il loro id, in Postgres il contatore dei nuovi id va portato avanti. @param array<int, string> $tables */
    private function resyncSequences(array $tables): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return; // SQLite tiene il conto da solo
        }

        foreach ($tables as $table) {
            if (! in_array('id', Schema::getColumnListing($table), true)) {
                continue;
            }
            $quoted = '"'.str_replace('"', '""', $table).'"';
            DB::select(
                "SELECT setval(pg_get_serial_sequence(?, 'id'), COALESCE((SELECT MAX(id) FROM {$quoted}), 1), (SELECT MAX(id) FROM {$quoted}) IS NOT NULL)",
                [$quoted],
            );
        }
    }
}
