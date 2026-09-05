<?php

namespace App\Policies;

use App\Enums\Ruolo;

/** Stesse soglie delle categorie: vedi CategoryPolicy. */
class TagPolicy extends PolicyDiSito
{
    protected const LETTURA = Ruolo::Viewer;
    protected const SCRITTURA = Ruolo::Editor;
    protected const ELIMINAZIONE = Ruolo::Editor;
}
