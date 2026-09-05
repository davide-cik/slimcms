<?php

namespace App\Policies;

use App\Enums\Ruolo;

/**
 * I reindirizzamenti non si leggono nemmeno sotto il grado di redattore.
 *
 * Non sono contenuto: vengono compilati in un `.htaccess` e cambiano cosa
 * risponde il server. Una riga sbagliata rende irraggiungibile una pagina
 * pubblicata, quindi la soglia e' la stessa di chi puo' pubblicare.
 */
class RedirectPolicy extends PolicyDiSito
{
    protected const LETTURA = Ruolo::Editor;
    protected const SCRITTURA = Ruolo::Editor;
    protected const ELIMINAZIONE = Ruolo::Editor;
}
