<?php

namespace App\Support;

use App\Services\Xfive\Admin\XfiveAdminException;
use Illuminate\Database\QueryException;
use PDOException;
use Throwable;

/**
 * Descrive un errore in modo adatto a un log o a un messaggio: per gli errori del database il messaggio di Laravel contiene
 * l'istruzione SQL con i valori (email, impronte delle password, dati personali), quindi si tiene solo il tipo e il codice SQLSTATE.
 */
final class SafeError
{
    public static function describe(Throwable $e): string
    {
        // i messaggi dell'accesso a XFive sono scritti per essere mostrati: mai credenziali né pezzi di pagina
        if ($e instanceof XfiveAdminException) {
            return $e->getMessage();
        }

        if ($e instanceof QueryException || $e instanceof PDOException) {
            return class_basename($e).' (SQLSTATE '.($e->getCode() ?: 'sconosciuto').')';
        }

        return class_basename($e).': '.mb_substr($e->getMessage(), 0, 200);
    }
}
