<?php

namespace App\Policies;

use App\Enums\Ruolo;
use App\Models\Site;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

/**
 * Base delle policy del data plane: tutte rispondono alla stessa domanda,
 * "che ruolo ha questa persona sul sito che sta guardando?", e cambiano solo
 * nella soglia richiesta per leggere, scrivere ed eliminare.
 *
 * Le dodici abilita' sono scritte qui una volta sola perche' Filament le
 * interroga tutte e una che manca **non nega: consente** (`helpers.php`,
 * `get_authorization_response()` cade fino a `Response::allow()` quando la
 * policy esiste ma il metodo no). Una policy scritta a mano che dimentica
 * `deleteAny` lascia aperta la cancellazione di massa e sembra completa.
 * `PolicyDiSitoTest` verifica che nessuna sottoclasse perda un'abilita'.
 */
abstract class PolicyDiSito
{
    /** Vedere l'elenco e i singoli record. */
    protected const LETTURA = Ruolo::Viewer;

    /** Creare e modificare. */
    protected const SCRITTURA = Ruolo::Editor;

    /** Eliminare, ripristinare, eliminare definitivamente. */
    protected const ELIMINAZIONE = Ruolo::Editor;

    public function viewAny(Authenticatable $utente): bool
    {
        return $this->almeno($utente, static::LETTURA);
    }

    public function view(Authenticatable $utente, Model $record): bool
    {
        return $this->almeno($utente, static::LETTURA, $record);
    }

    public function create(Authenticatable $utente): bool
    {
        return $this->almeno($utente, static::SCRITTURA);
    }

    public function update(Authenticatable $utente, Model $record): bool
    {
        return $this->almeno($utente, static::SCRITTURA, $record);
    }

    public function replicate(Authenticatable $utente, Model $record): bool
    {
        return $this->almeno($utente, static::SCRITTURA, $record);
    }

    public function reorder(Authenticatable $utente): bool
    {
        return $this->almeno($utente, static::SCRITTURA);
    }

    public function delete(Authenticatable $utente, Model $record): bool
    {
        return $this->almeno($utente, static::ELIMINAZIONE, $record);
    }

    public function deleteAny(Authenticatable $utente): bool
    {
        return $this->almeno($utente, static::ELIMINAZIONE);
    }

    public function restore(Authenticatable $utente, Model $record): bool
    {
        return $this->almeno($utente, static::ELIMINAZIONE, $record);
    }

    public function restoreAny(Authenticatable $utente): bool
    {
        return $this->almeno($utente, static::ELIMINAZIONE);
    }

    /**
     * Eliminare definitivamente e' sempre dell'amministratore, qualunque sia
     * la soglia del modello: e' l'unica azione del pannello che non si puo'
     * annullare.
     */
    public function forceDelete(Authenticatable $utente, Model $record): bool
    {
        return $this->almeno($utente, Ruolo::Admin, $record);
    }

    public function forceDeleteAny(Authenticatable $utente): bool
    {
        return $this->almeno($utente, Ruolo::Admin);
    }

    protected function almeno(Authenticatable $utente, Ruolo $minimo, ?Model $record = null): bool
    {
        $ruolo = $this->ruolo($utente, $record);

        return $ruolo !== null && $ruolo->almeno($minimo);
    }

    /**
     * Il ruolo dell'utente sul sito del pannello, dopo aver verificato che il
     * record sia davvero di quel sito.
     *
     * Il controllo sul `site_id` e' ridondante — le query del pannello sono
     * gia' scoped — ed e' li' proprio per questo: se un record di un altro
     * cliente arriva fin qui qualcosa si e' rotto a monte, e la risposta
     * giusta a un errore che non dovrebbe accadere e' no.
     */
    protected function ruolo(Authenticatable $utente, ?Model $record = null): ?Ruolo
    {
        // Il tipo e' Authenticatable e non User perche' il Gate consegna
        // l'utente della guardia attiva, qualunque essa sia: da una pagina
        // del control plane arriva un AdminUser, che qui dentro non ha ruoli
        // e non ne deve avere — chi amministra la piattaforma entra nel
        // pannello di un sito impersonando un redattore, non per diritto
        // proprio. Con `User` nella firma era un errore di tipo, cioe' un
        // 500 al posto di un no.
        if (! $utente instanceof User) {
            return null;
        }

        $sito = Filament::getTenant();

        if (! $sito instanceof Site) {
            return null;
        }

        if ($record !== null
            && $record->getAttribute('site_id') !== null
            && (int) $record->getAttribute('site_id') !== (int) $sito->getKey()) {
            return null;
        }

        return $utente->ruolo($sito);
    }
}
