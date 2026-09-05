<?php

namespace App\Policies;

use App\Enums\Ruolo;

/**
 * L'elenco dei 404 esiste per decidere un reindirizzamento: chi non puo'
 * crearlo non ha motivo di vederlo.
 *
 * Nessuno crea una riga a mano — le scrive l'importazione da cron — ma la
 * soglia di scrittura resta dichiarata: l'azione "ignora" della tabella e'
 * un update, e senza soglia sarebbe un permesso implicito.
 */
class PaginaMancantePolicy extends PolicyDiSito
{
    protected const LETTURA = Ruolo::Editor;
    protected const SCRITTURA = Ruolo::Editor;
    protected const ELIMINAZIONE = Ruolo::Editor;
}
