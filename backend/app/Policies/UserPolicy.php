<?php

namespace App\Policies;

use App\Enums\Ruolo;
use App\Models\Site;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

/**
 * I redattori li gestisce solo l'amministratore del sito.
 *
 * Era il buco piu' grosso del pannello: senza policy la risorsa era aperta a
 * chiunque avesse accesso, quindi un redattore poteva aprire
 * `/admin/<sito>/users` e promuoversi ad amministratore. Il ruolo lo si
 * assegnava da soli.
 */
class UserPolicy extends PolicyDiSito
{
    protected const LETTURA = Ruolo::Admin;
    protected const SCRITTURA = Ruolo::Admin;
    protected const ELIMINAZIONE = Ruolo::Admin;

    /**
     * Qui "eliminare" vuol dire togliere dal sito (vedi `EditUser`).
     *
     * Toglierne se stessi chiuderebbe la porta da dentro: se e' l'ultimo
     * amministratore, il sito resta senza nessuno che possa nominarne un
     * altro, e rimediare richiede un intervento dal control plane.
     */
    public function delete(Authenticatable $utente, Model $record): bool
    {
        return ! $record->is($utente) && parent::delete($utente, $record);
    }

    public function deleteAny(Authenticatable $utente): bool
    {
        // Una cancellazione di massa non puo' escludere se stessi riga per
        // riga: qui l'azione non esiste proprio.
        return false;
    }

    /**
     * L'account non si distrugge dal pannello di un sito: la stessa persona
     * puo' lavorare su altri siti, che da qui non si vedono.
     */
    public function forceDelete(Authenticatable $utente, Model $record): bool
    {
        return false;
    }

    public function forceDeleteAny(Authenticatable $utente): bool
    {
        return false;
    }

    /**
     * Un utente non e' scoped da una colonna `site_id` ma da un pivot, quindi
     * il controllo della classe base non lo tocca: va fatto qui, altrimenti
     * un id indovinato nella URL basterebbe a modificare il redattore di un
     * altro cliente.
     */
    protected function ruolo(Authenticatable $utente, ?Model $record = null): ?Ruolo
    {
        $sito = Filament::getTenant();

        if ($record instanceof User && $sito instanceof Site
            && ! $record->sites()->withoutTenancy()->whereKey($sito->getKey())->exists()) {
            return null;
        }

        return parent::ruolo($utente, $record);
    }
}
