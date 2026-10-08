<?php

namespace App\Services\Xfive\Admin;

use RuntimeException;

/**
 * Un problema con l'accesso a XFive. Il messaggio si può mostrare e registrare: non contiene mai credenziali, indirizzi
 * con dati o pezzi di pagina.
 */
final class XfiveAdminException extends RuntimeException
{
    /** @param 'not_configured'|'disabled'|'blocked'|'login_failed'|'unreachable'|'unexpected_page' $state */
    public function __construct(public readonly string $state, string $message)
    {
        parent::__construct($message);
    }
}
