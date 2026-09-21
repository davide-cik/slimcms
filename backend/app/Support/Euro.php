<?php

namespace App\Support;

/**
 * Euro nei form, centesimi nel database.
 *
 * Chi compila scrive "39,90"; il campo `numeric` di Filament lo rifiuterebbe
 * per la virgola, e un float nel database sbaglierebbe i conti. La
 * conversione sta qui, in un posto solo.
 *
 * Regole di lettura di `inCentesimi()`:
 *
 * - Solo numeri decimali senza segno: niente "-5", niente notazione
 *   esponenziale ("1e3"). Un importo negativo o scritto in quella forma non
 *   e' un errore che deve arrivare a MariaDB: la colonna e' un
 *   unsignedInteger, e ci arriverebbe come un 500 al posto di un errore nel
 *   campo.
 * - Senza virgola, un testo a gruppi di tre cifre dopo il punto
 *   (`/^\d{1,3}(\.\d{3})+$/`) ha i punti come separatore delle migliaia:
 *   "1.500" -> 150000, "12.000" -> 1200000, "1.234" -> 123400. Altrimenti
 *   l'unico punto e' decimale: "39.90" -> 3990, "39.9" -> 3990. E' la stessa
 *   ambiguita' di chi legge un prezzo scritto a mano: un gruppo di ESATTAMENTE
 *   tre cifre dopo il punto si legge come le migliaia, non come i centesimi.
 * - Con la virgola, ogni punto e' sempre separatore delle migliaia
 *   ("1.234,50" -> 123450) e la virgola e' il separatore decimale.
 * - Al massimo due cifre decimali: una terza dopo la virgola ("39,999") non
 *   si arrotonda in silenzio, e' un importo illeggibile e diventa null.
 * - Spazio normale, spazio non-interrompibile (frequente incollando da una
 *   pagina web) e "€" si tolgono prima di leggere il numero.
 *
 * I centesimi si calcolano spezzando la stringa in parte intera e parte
 * decimale, non moltiplicando un float per 100: 0,29 * 100 in virgola mobile
 * fa 28,999..., e arrotondare dopo il fatto sarebbe una toppa sullo stesso
 * errore invece di evitarlo.
 */
final class Euro
{
    /**
     * Il massimo che sta in un unsignedInteger di MariaDB, come le colonne
     * `prodotti.prezzo` e `prodotti.prezzo_barrato`. Non e' un limite di
     * `inCentesimi()` (che e' solo lettura di un numero, non conosce lo
     * schema): lo usa chi valida un campo che scrive su una di quelle
     * colonne, per dare un errore nel form invece di un 500 dal database.
     */
    public const MASSIMO_CENTESIMI = 4294967295;

    /** Gruppi di ESATTAMENTE tre cifre dopo il punto: sono le migliaia. */
    private const FORMATO_MIGLIAIA = '/^\d{1,3}(\.\d{3})+$/';

    /** Un numero decimale senza segno, al massimo due cifre dopo il punto. */
    private const FORMATO_VALIDO = '/^\d+(\.\d{1,2})?$/';

    public static function inCentesimi(string|int|float|null $valore): ?int
    {
        if ($valore === null || $valore === '') {
            return null;
        }

        // "\xC2\xA0" e' lo spazio non-interrompibile (U+00A0): compare
        // incollando un prezzo da una pagina web, e senza toglierlo qui il
        // numero risulta illeggibile come se ci fosse un carattere qualsiasi.
        $testo = str_replace([' ', "\xC2\xA0", '€'], '', (string) $valore);

        if ($testo === '') {
            return null;
        }

        if (str_contains($testo, ',')) {
            // Con la virgola, il punto e' sempre separatore delle migliaia.
            $testo = str_replace(['.', ','], ['', '.'], $testo);
        } elseif (preg_match(self::FORMATO_MIGLIAIA, $testo) === 1) {
            // Senza virgola ma a gruppi di tre cifre: sono le migliaia, non
            // i decimali. "1.500" e' millecinquecento, non uno virgola
            // cinquanta.
            $testo = str_replace('.', '', $testo);
        }
        // Altrimenti l'eventuale unico punto resta com'e': e' il separatore
        // decimale ("39.90"), o non c'e' affatto ("39").

        if (preg_match(self::FORMATO_VALIDO, $testo) !== 1) {
            return null;
        }

        // Piu' di 15 cifre intere non e' un prezzo: e' un modo di far
        // traboccare l'intero PHP a 64 bit, che qui non serve prevenire con
        // un tetto qualsiasi ma con uno abbondantemente sopra ogni importo
        // reale.
        [$interi, $decimali] = array_pad(explode('.', $testo, 2), 2, '');

        if (strlen($interi) > 15) {
            return null;
        }

        return ((int) $interi) * 100 + (int) str_pad($decimali, 2, '0');
    }

    public static function daCentesimi(?int $centesimi): ?string
    {
        return $centesimi === null ? null : number_format($centesimi / 100, 2, ',', '');
    }
}
