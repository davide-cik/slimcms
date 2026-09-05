<?php

namespace App\Policies;

use App\Enums\Ruolo;

/**
 * Le pagine le scrive anche un autore; pubblicarle no.
 *
 * La pubblicazione non e' un'abilita' di questa policy perche' non e'
 * un'azione del pannello: e' un valore del campo `status`. La guardia sta
 * sul modello (`PubblicazioneRiservata`), dove nessuna richiesta forgiata la
 * scavalca, e il form si limita a non offrire l'opzione.
 *
 * Eliminare resta al redattore: un autore che non puo' pubblicare ma puo'
 * cancellare la home sarebbe un ruolo piu' pericoloso di quello sopra.
 */
class PagePolicy extends PolicyDiSito
{
    protected const LETTURA = Ruolo::Viewer;
    protected const SCRITTURA = Ruolo::Author;
    protected const ELIMINAZIONE = Ruolo::Editor;
}
