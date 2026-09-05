<?php

namespace App\Policies;

use App\Enums\Ruolo;

/**
 * Categorie e tag li vedono tutti — servono per compilare un articolo — ma
 * crearli e' del redattore: sono la struttura del sito, non il suo
 * contenuto, e finiscono in URL pubbliche di archivio. Un autore sceglie fra
 * quelli che esistono.
 */
class CategoryPolicy extends PolicyDiSito
{
    protected const LETTURA = Ruolo::Viewer;
    protected const SCRITTURA = Ruolo::Editor;
    protected const ELIMINAZIONE = Ruolo::Editor;
}
