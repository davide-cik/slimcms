<?php

namespace App\Policies;

use App\Enums\Ruolo;

/**
 * I messaggi dei visitatori li legge chi ha il grado di redattore.
 *
 * Portano nome, email e testo di una persona che ha scritto al sito: sono
 * dati di terzi, non contenuto del sito, e non vanno sotto gli occhi di
 * chiunque abbia accesso al pannello. Nessuno li crea a mano — arrivano dal
 * form — ma la soglia di scrittura resta dichiarata perche' "segna come
 * letto" e' un update.
 */
class MessaggioPolicy extends PolicyDiSito
{
    protected const LETTURA = Ruolo::Editor;
    protected const SCRITTURA = Ruolo::Editor;
    protected const ELIMINAZIONE = Ruolo::Editor;
}
