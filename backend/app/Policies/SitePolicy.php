<?php

namespace App\Policies;

use App\ControlPlane\Models\AdminUser;
use App\Enums\Ruolo;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Le impostazioni del sito le cambia solo chi amministra quel sito.
 *
 * Non e' contenuto: da qui si decide come si chiama il sito nella scheda del
 * browser, che icona ha, come e' fatta la testata. Un redattore scrive dentro
 * il vestito, non lo cambia.
 *
 * Attenzione al doppio uso di questo modello. `Site` compare in **due**
 * pannelli: qui e' il tenant del pannello dei contenuti, ma nel control plane
 * e' una risorsa gestita da un `AdminUser`, che su un sito non ha e non deve
 * avere un ruolo. Aggiungere una policy a un modello cambia il comportamento
 * di **tutti** i pannelli che lo usano — prima non ce n'era e Filament
 * consentiva — quindi senza l'eccezione qui sotto questo file chiuderebbe
 * fuori i super-admin dalla loro stessa lista dei siti.
 */
class SitePolicy extends PolicyDiSito
{
    protected const LETTURA = Ruolo::Admin;
    protected const SCRITTURA = Ruolo::Admin;
    protected const ELIMINAZIONE = Ruolo::Admin;

    protected function almeno(Authenticatable $utente, Ruolo $minimo, ?Model $record = null): bool
    {
        // Il control plane ha le proprie guardie: la lista dei siti e' gia'
        // filtrata per cliente in `SiteResource::getEloquentQuery()`, e chi
        // non e' super-admin vede solo i propri. Qui non c'e' niente da
        // aggiungere, e negare vorrebbe dire rompere quel pannello.
        if ($utente instanceof AdminUser) {
            return true;
        }

        return parent::almeno($utente, $minimo, $record);
    }
}
