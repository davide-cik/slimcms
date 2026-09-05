<?php

namespace App\Policies;

use App\Enums\Ruolo;

/** Come le pagine: l'autore scrive, il redattore pubblica ed elimina. */
class PostPolicy extends PolicyDiSito
{
    protected const LETTURA = Ruolo::Viewer;
    protected const SCRITTURA = Ruolo::Author;
    protected const ELIMINAZIONE = Ruolo::Editor;
}
