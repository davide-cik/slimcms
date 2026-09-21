<?php

namespace App\Policies;

use App\Enums\Ruolo;

/**
 * Come pagine e articoli: l'autore scrive, il redattore pubblica ed elimina.
 * Pubblicare e' controllato a parte, sul modello (PubblicazioneRiservata).
 */
class ProdottoPolicy extends PolicyDiSito
{
    protected const LETTURA = Ruolo::Viewer;
    protected const SCRITTURA = Ruolo::Author;
    protected const ELIMINAZIONE = Ruolo::Editor;
}
