<?php

namespace App\Support;

use App\Enums\Ruolo;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;

/**
 * Il ruolo di chi sta usando il pannello, sul sito che sta guardando.
 *
 * E' l'unico punto in cui si risponde a quella domanda. Le policy la fanno
 * per decidere, i form per nascondere quello che non si potrebbe comunque
 * fare: se la risposta la calcolassero separatamente, prima o poi un form
 * offrirebbe qualcosa che la policy rifiuta — un pulsante che da' errore e'
 * peggio di un pulsante che non c'e'.
 *
 * Fuori dal pannello la risposta e' `null` e chi la riceve nega. Comandi,
 * seeder e job non passano di qui: il loro confine e' l'inizializzazione
 * esplicita del tenant (regola 2 di CLAUDE.md), non un ruolo.
 */
class RuoloCorrente
{
    public static function nelPannello(): ?Ruolo
    {
        $sito = Filament::getTenant();
        $utente = auth('web')->user();

        if (! $sito instanceof Site || ! $utente instanceof User) {
            return null;
        }

        return $utente->ruolo($sito);
    }

    /** Comodita' per i form: "posso pubblicare su questo sito?" */
    public static function puoPubblicare(): bool
    {
        return (bool) self::nelPannello()?->puoPubblicare();
    }

    /**
     * Il ruolo che chi sta usando il pannello puo' davvero concedere a un
     * altro redattore.
     *
     * Nessuno concede piu' di quanto ha: senza questo limite basterebbe
     * cambiare il valore della tendina prima dell'invio per nominare un
     * amministratore. Oggi al form ci arriva solo un amministratore
     * (`UserPolicy`), quindi il limite non morde mai — ed e' il momento
     * giusto per scriverlo, perche' il giorno in cui un redattore potra'
     * invitare un autore nessuno si ricordera' di aggiungerlo.
     */
    public static function concedibile(?string $richiesto): Ruolo
    {
        $proprio = self::nelPannello();

        abort_if($proprio === null, 403, 'Nessun ruolo su questo sito.');

        // Un valore che non e' un ruolo non e' un motivo per concedere di
        // piu': si ricade sul gradino piu' basso che sa ancora scrivere.
        $ruolo = Ruolo::da($richiesto) ?? Ruolo::Author;

        return $ruolo->livello() > $proprio->livello() ? $proprio : $ruolo;
    }
}
