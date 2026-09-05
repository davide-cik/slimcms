<?php

namespace App\Policies;

use App\Enums\Ruolo;

/**
 * I moduli li configura il redattore.
 *
 * Non sono contenuto: definiscono dove finiscono i dati di una persona che
 * scrive al sito, e cambiare il destinatario di un modulo e' come cambiare
 * l'indirizzo a cui arriva la posta.
 */
class ModuloPolicy extends PolicyDiSito
{
    protected const LETTURA = Ruolo::Viewer;
    protected const SCRITTURA = Ruolo::Editor;
    protected const ELIMINAZIONE = Ruolo::Editor;
}
