<?php

namespace App\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Copia di sicurezza e ripristino dell'intero database: tutto quello che l'app sa sta in un solo file SQLite.
 * Serve soprattutto per spostare i dati dal proprio computer al server, e per avere una copia a portata di mano.
 */
class DatabaseBackup
{
    /** Senza queste tabelle un file non è il database di questa app. */
    private const REQUIRED_TABLES = ['migrations', 'users', 'players', 'matches', 'teams', 'competitions'];

    /**
     * Stato di servizio, non dati: sessioni e token di accesso, cache, code. Nei backup non c'entrano e non si ripristinano,
     * per due motivi: un vecchio backup farebbe rivivere accessi già revocati, e cache e code contengono oggetti PHP
     * serializzati che l'app rilegge, quindi un file costruito ad arte non deve poterli portare dentro.
     */
    /** I nomi si leggono dalla configurazione: se qualcuno li cambia (es. DB_CACHE_TABLE) la pulizia segue. */
    private function runtimeTables(): array
    {
        return array_values(array_unique(array_filter([
            'personal_access_tokens',
            config('session.table', 'sessions'),
            config('auth.passwords.'.config('auth.defaults.passwords').'.table', 'password_reset_tokens'),
            config('cache.stores.database.table', 'cache'),
            config('cache.stores.database.lock_table', 'cache_locks'),
            config('queue.connections.database.table', 'jobs'),
            config('queue.batching.table', 'job_batches'),
            config('queue.failed.table', 'failed_jobs'),
        ], 'is_string')));
    }

    /** Il file del database in uso. */
    public function path(): string
    {
        $path = (string) config('database.connections.sqlite.database');

        if ($path === '' || $path === ':memory:') {
            throw new RuntimeException('Il database non è un file: non si può copiare.');
        }

        return $path;
    }

    /** Crea una copia coerente (anche mentre l'app lavora) e ne restituisce il percorso, da cancellare dopo l'uso. */
    public function export(): string
    {
        $target = tempnam(sys_get_temp_dir(), 'amir-backup-');

        if ($target === false) {
            throw new RuntimeException('Non riesco a preparare la copia.');
        }

        // VACUUM INTO scrive una copia compatta e consistente; il file di destinazione deve essere vuoto
        DB::statement('VACUUM INTO '.DB::getPdo()->quote($target));
        $this->clearRuntimeState($target);

        return $target;
    }

    /**
     * Sostituisce il database con quello contenuto nel file indicato (dopo averlo controllato).
     * La versione precedente resta accanto, con il suffisso .prima-del-ripristino, per poter tornare indietro.
     *
     * @throws InvalidArgumentException se il file non è un database di questa app
     */
    public function restore(string $uploaded): void
    {
        $db = $this->path();
        $stage = $db.'.ripristino';

        if (! copy($uploaded, $stage)) {
            throw new RuntimeException('Non riesco a leggere il file caricato.');
        }

        // si controlla e si ripulisce proprio la copia che diventerà il database, non il file caricato: nel mezzo non può cambiare
        try {
            $this->assertIsOurDatabase($stage);
            $this->clearRuntimeState($stage);
        } catch (\Throwable $e) {
            @unlink($stage);

            throw $e;
        }

        DB::disconnect();

        if (is_file($db)) {
            copy($db, $db.'.prima-del-ripristino');
        }
        foreach (['-wal', '-shm', '-journal'] as $suffix) {
            if (is_file($db.$suffix)) {
                unlink($db.$suffix);
            }
        }

        if (! rename($stage, $db)) {
            @unlink($stage);

            throw new RuntimeException('Non riesco a sostituire il database.');
        }

        DB::purge();
        // un backup fatto con una versione più vecchia dell'app si aggiorna da solo
        Artisan::call('migrate', ['--force' => true]);
    }

    /** Svuota le tabelle di servizio (vedi RUNTIME_TABLES) del file indicato, che non deve essere quello in uso. */
    private function clearRuntimeState(string $file): void
    {
        $pdo = new PDO('sqlite:'.$file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $existing = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);

        // SQLite non distingue maiuscole e minuscole nei nomi: «CACHE» e «cache» sono la stessa tabella per l'app
        $wanted = array_map('strtolower', $this->runtimeTables());

        foreach ($existing as $table) {
            if (in_array(strtolower((string) $table), $wanted, true)) {
                $pdo->exec('DELETE FROM "'.str_replace('"', '""', (string) $table).'"');
            }
        }
    }

    /** Nomi delle tabelle del file indicato (senza quelle interne di SQLite). */
    private function tablesOf(PDO $pdo): array
    {
        $names = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);

        return array_values(array_filter($names, fn ($n) => ! str_starts_with((string) $n, 'sqlite_')));
    }

    private function assertIsOurDatabase(string $file): void
    {
        $head = (string) file_get_contents($file, false, null, 0, 15);

        if ($head !== 'SQLite format 3') {
            throw new InvalidArgumentException('Il file non è un database SQLite.');
        }

        try {
            $pdo = new PDO('sqlite:'.$file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

            if ($pdo->query('PRAGMA quick_check')->fetchColumn() !== 'ok') {
                throw new InvalidArgumentException('Il file è danneggiato.');
            }

            $tables = $this->tablesOf($pdo);
            $missing = array_diff(self::REQUIRED_TABLES, $tables);

            if ($missing !== []) {
                throw new InvalidArgumentException('Non è un backup di AMIR Team Manager (mancano: '.implode(', ', $missing).').');
            }

            // un backup vero ha solo le tabelle di questa app: niente trigger, viste o tabelle che non conosciamo
            // (un file costruito ad arte potrebbe nasconderci istruzioni che girano a ogni scrittura)
            if ((int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type IN ('trigger', 'view')")->fetchColumn() > 0) {
                throw new InvalidArgumentException('Il file contiene trigger o viste: un backup di AMIR Team Manager non ne ha.');
            }

            $current = $this->tablesOf(DB::getPdo());
            $unknown = array_diff($tables, $current);

            if ($unknown !== []) {
                throw new InvalidArgumentException('Il file contiene tabelle che questa versione non conosce ('.implode(', ', $unknown).'): viene da una versione più recente? Aggiorna prima il server.');
            }

            if ((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
                throw new InvalidArgumentException('Il backup non contiene nessun utente: dopo il ripristino non potresti più accedere.');
            }
        } catch (\PDOException) {
            throw new InvalidArgumentException('Il file non si può leggere come database.');
        }
    }
}
