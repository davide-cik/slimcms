<?php

namespace App\Services;

use App\Models\Redirect;
use Illuminate\Support\Collection;

/**
 * Compila i reindirizzamenti di un sito in un file .htaccess.
 *
 * Il sito pubblico e' statico: nessuna richiesta passa da Laravel, quindi i
 * redirect non possono essere una query. Vengono compilati qui e depositati
 * nel sito in fase di build, come la mappa di routing dell'edge (§7.2).
 *
 * Verificato sul server: nginx serve direttamente i file con estensione nota
 * e passa ad Apache tutto il resto, compresi gli indirizzi "puliti" con lo
 * slash finale che sono quelli che usiamo. mod_alias e mod_rewrite rispondono
 * entrambi, e AllowOverride e' All.
 */
class GeneratoreHtaccess
{
    /** Il gestore della pagina d'errore, generato nel sito dalla build. */
    public const GESTORE_404 = 'slimcms-404.php';

    /**
     * @param  Collection<int, Redirect>  $redirect
     */
    public function genera(Collection $redirect): string
    {
        $regole = $this->appiattisci(
            $redirect
                ->filter(fn (Redirect $r) => $r->attivo)
                // Cintura, oltre alle bretelle della validazione nel form:
                // Apache risponde 500 su TUTTO il sito se il .htaccess non e'
                // valido, e uno spazio o un a capo in un percorso basta a
                // renderlo tale. Una riga scartata fa perdere un redirect;
                // una riga malformata fa perdere il sito.
                ->filter(fn (Redirect $r) => $this->sicura($r->da) && $this->sicura($r->a))
        );

        $righe = [
            '# Generato da SlimCMS: non modificare a mano, viene riscritto a ogni',
            '# pubblicazione. I reindirizzamenti si cambiano dal pannello.',
            '',
            // La pagina d'errore del sito, non quella dell'hosting: senza
            // questa riga ogni cliente mostra il 404 inglese di HestiaCP.
            //
            // Punta al gestore PHP e non direttamente a 404.html perche' quel
            // gestore, oltre a stampare la pagina, annota l'indirizzo mancante
            // in una cartella privata. E' l'unico modo di sapere quali
            // collegamenti sono rotti su un sito che non passa da Laravel.
            'ErrorDocument 404 /' . self::GESTORE_404,
        ];

        if ($regole->isNotEmpty()) {
            $righe[] = '';
            $righe[] = '<IfModule mod_rewrite.c>';
            $righe[] = 'RewriteEngine On';

            foreach ($regole as $da => $regola) {
                $righe[] = '';
                // Le due condizioni valgono solo per la regola che segue e
                // vanno quindi ripetute. Servono a non rendere irraggiungibile
                // una pagina che esiste davvero: se il file o la cartella ci
                // sono, il redirect non scatta. Un redirect che oscura una
                // pagina pubblicata e' un guasto che si scopre dal cliente.
                $righe[] = 'RewriteCond %{REQUEST_FILENAME} !-f';
                $righe[] = 'RewriteCond %{REQUEST_FILENAME} !-d';
                $righe[] = sprintf(
                    'RewriteRule ^%s/?$ %s [R=%d,L]',
                    $this->perRegex(ltrim($da, '/')),
                    $regola['a'],
                    $regola['codice']
                );
            }

            $righe[] = '';
            $righe[] = '</IfModule>';
        }

        return implode("\n", $righe) . "\n";
    }

    /**
     * Risolve le catene: A->B e B->C diventano A->C e B->C.
     *
     * Una catena costa un giro di rete in piu' a ogni visita e i motori ne
     * seguono un numero limitato prima di rinunciare. Appiattirla qui, alla
     * generazione, e' piu' semplice che vietarla all'inserimento: chi crea
     * B->C mesi dopo A->B non sta sbagliando niente.
     *
     * @param  Collection<int, Redirect>  $redirect
     * @return Collection<string, array{a: string, codice: int}>
     */
    private function appiattisci(Collection $redirect): Collection
    {
        $per = $redirect->keyBy(fn (Redirect $r) => Redirect::normalizza($r->da));

        return $per
            ->map(function (Redirect $partenza) use ($per) {
                $destinazione = $partenza->a;
                $codice = $partenza->codice;
                $visti = [Redirect::normalizza($partenza->da)];

                for ($i = 0; $i < 10; $i++) {
                    if (str_contains($destinazione, '://')) {
                        break;
                    }

                    $prossima = Redirect::normalizza($destinazione);

                    // Anello: A->B->A, oppure A->A. Non si appiattisce in
                    // niente di valido, e la regola va TOLTA. Lasciarla con
                    // l'ultima destinazione raggiunta produrrebbe un redirect
                    // su se stesso, cioe' un browser che gira finche' non si
                    // arrende — peggio del 404 che stavamo evitando.
                    if (in_array($prossima, $visti, true)) {
                        return null;
                    }

                    if (! $per->has($prossima)) {
                        break;
                    }

                    $visti[] = $prossima;
                    $seguente = $per->get($prossima);
                    $destinazione = $seguente->a;

                    // Se una tappa della catena e' temporanea, la somma non
                    // e' permanente.
                    $codice = $seguente->codice === 302 ? 302 : $codice;
                }

                // Una regola che punta a se stessa dopo la normalizzazione e'
                // lo stesso anello, lungo uno.
                if (! str_contains($destinazione, '://')
                    && Redirect::normalizza($destinazione) === Redirect::normalizza($partenza->da)) {
                    return null;
                }

                return ['a' => $destinazione, 'codice' => $codice];
            })
            ->filter();
    }

    /**
     * Mette al riparo i caratteri speciali dell'espressione regolare.
     *
     * Un percorso e' testo scritto da chi redige: senza questo, un punto in
     * "vecchia.pagina" corrisponderebbe a qualsiasi carattere, e una parentesi
     * romperebbe la sintassi del file — cioe' TUTTO il sito, perche' Apache
     * risponde 500 su un .htaccess non valido.
     */
    private function perRegex(string $percorso): string
    {
        return preg_replace('/[.\\\\+*?\[\]^$(){}=!<>|:#\/-]/', '\\\\$0', $percorso)
            ?? preg_quote($percorso, '/');
    }

    /**
     * Un valore e' scrivibile nel file solo se non contiene spazi ne'
     * caratteri di controllo: sono i due modi in cui una riga si spezza.
     */
    private function sicura(?string $valore): bool
    {
        $valore = (string) $valore;

        return $valore !== '' && preg_match('/[\s\x00-\x1f\x7f]/', $valore) !== 1;
    }
}
