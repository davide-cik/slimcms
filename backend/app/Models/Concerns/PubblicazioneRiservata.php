<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

/**
 * Impedisce a chi non ha il grado di redattore di mettere online un contenuto.
 *
 * Sta sul modello e non solo nel form perche' una tendina disabilitata non e'
 * un controllo: lo stato di un componente Livewire arriva dal browser, e chi
 * vuole pubblicare senza permesso non passa dalla tendina. Il form nasconde
 * l'opzione, questo la rifiuta.
 *
 * `scheduled` conta quanto `published`: rimandare la pubblicazione di un'ora
 * resta pubblicare, solo piu' tardi.
 *
 * Fuori dal pannello non c'e' nessuno da controllare — comandi, seeder,
 * migrazioni e importazioni girano senza utente autenticato — e il confine
 * li' e' un altro (regola 2 di CLAUDE.md). Se pero' un utente c'e', deve
 * avere un ruolo su quel sito: un utente autenticato che salva il contenuto
 * di un sito su cui non ha ruolo e' esattamente il caso da fermare.
 */
trait PubblicazioneRiservata
{
    public static function bootPubblicazioneRiservata(): void
    {
        static::saving(function (Model $modello): void {
            if (! in_array($modello->status, ['published', 'scheduled'], true)) {
                return;
            }

            // Salvare di nuovo un contenuto gia' online non e' pubblicare:
            // un autore deve poter correggere un refuso in una pagina viva.
            if ($modello->exists && ! $modello->isDirty('status')) {
                return;
            }

            $utente = auth('web')->user();

            if (! $utente instanceof User) {
                return;
            }

            // `saving` scatta PRIMA di `creating`, quindi alla prima
            // creazione `site_id` e' ancora vuoto: l'assegnazione automatica
            // non e' ancora avvenuta. Chiedere il ruolo su `null` negherebbe
            // a chiunque di pubblicare una pagina nuova. Si ricade sul sito
            // corrente, che e' la stessa fonte da cui `BelongsToSite` sta per
            // prendere la colonna.
            $sito = $modello->site_id ?? BelongsToSite::currentSiteId();

            if ($utente->ruolo($sito)?->puoPubblicare()) {
                return;
            }

            throw new AuthorizationException(
                'Il tuo ruolo su questo sito non consente di pubblicare: salva come bozza e chiedi a un redattore.'
            );
        });
    }
}
