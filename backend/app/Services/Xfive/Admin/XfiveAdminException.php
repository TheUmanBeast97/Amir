<?php

namespace App\Services\Xfive\Admin;

use RuntimeException;

/**
 * Un problema con l'accesso a XFive. Il messaggio si può mostrare e registrare: non contiene mai credenziali, indirizzi
 * con dati o pezzi di pagina.
 */
final class XfiveAdminException extends RuntimeException
{
    /**
     * @param 'not_configured'|'disabled'|'blocked'|'login_failed'|'unreachable'|'unexpected_page'|'not_connected'|'session_expired'|'not_open'|'rejected'|'invalid' $state
     *   not_connected: nessun «Collega XFive»; session_expired: XFive ha chiuso la sessione; not_open: la distinta non si può aprire adesso;
     *   rejected: XFive ha rifiutato i dati inviati; invalid: i dati non passano i controlli prima dell'invio
     */
    public function __construct(public readonly string $state, string $message)
    {
        parent::__construct($message);
    }
}
