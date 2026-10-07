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
        $this->assertIsOurDatabase($uploaded);

        $db = $this->path();
        $stage = $db.'.ripristino';

        if (! copy($uploaded, $stage)) {
            throw new RuntimeException('Non riesco a leggere il file caricato.');
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

            $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
            $missing = array_diff(self::REQUIRED_TABLES, $tables);

            if ($missing !== []) {
                throw new InvalidArgumentException('Non è un backup di AMIR Team Manager (mancano: '.implode(', ', $missing).').');
            }

            if ((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
                throw new InvalidArgumentException('Il backup non contiene nessun utente: dopo il ripristino non potresti più accedere.');
            }
        } catch (\PDOException) {
            throw new InvalidArgumentException('Il file non si può leggere come database.');
        }
    }
}
