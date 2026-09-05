<?php

namespace App\Policies;

use App\Enums\Ruolo;

/**
 * Un autore carica le immagini dei propri contenuti, ma non cancella quelle
 * degli altri: un file eliminato dalla libreria lascia un riquadro rotto in
 * ogni pagina che lo usava, e da qui non si vede quali siano.
 */
class MediaPolicy extends PolicyDiSito
{
    protected const LETTURA = Ruolo::Viewer;
    protected const SCRITTURA = Ruolo::Author;
    protected const ELIMINAZIONE = Ruolo::Editor;
}
