<?php

namespace App\Policies;

use App\Enums\Ruolo;
use App\Models\Site;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Come pagine e articoli: l'autore scrive, il redattore pubblica ed elimina.
 * Pubblicare e' controllato a parte, sul modello (PubblicazioneRiservata).
 */
class ProdottoPolicy extends PolicyDiSito
{
    protected const LETTURA = Ruolo::Viewer;
    protected const SCRITTURA = Ruolo::Author;
    protected const ELIMINAZIONE = Ruolo::Editor;

    /**
     * `ProdottoResource::canAccess()` nasconde la voce di menu a negozio
     * spento, ma nasconderla non e' negarla: un `createOptionForm`, un
     * relation manager o un `Select` di upsell (passo 2) chiedono
     * direttamente al Gate, scavalcando la risorsa. Sovrascrivendo qui —
     * e non in `almeno()` — il `null` si propaga a tutte e dodici le
     * abilita' di `PolicyDiSito`, `forceDelete` compreso, perche' passa
     * anche lui da `almeno()` -> `ruolo()`.
     */
    protected function ruolo(Authenticatable $utente, ?Model $record = null): ?Ruolo
    {
        $sito = Filament::getTenant();

        if (! $sito instanceof Site || ! $sito->negozioAttivo()) {
            return null;
        }

        return parent::ruolo($utente, $record);
    }
}
