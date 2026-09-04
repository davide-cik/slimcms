<?php

namespace App\Support;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;

/**
 * L'unico punto in cui si costruisce uno slug.
 *
 * Prima `Str::slug()` era chiamata in nove file diversi — form delle pagine,
 * degli articoli, delle categorie, dei tag, dei tenant, migrazioni, seeder —
 * ognuno con la propria copia della regola. Cambiarla voleva dire ricordarsi
 * di tutti e nove, e gli slug gia' pubblicati sono indirizzi: divergere
 * significa che due contenuti creati in punti diversi dell'applicazione
 * finiscono su URL con regole diverse.
 *
 * `Str::slug()` da sola non basta per l'italiano. Toglie i caratteri che non
 * riconosce invece di trattarli come separatori, e il risultato **unisce** le
 * parole:
 *
 *   Sant'Angelo    -> santangelo    (invece di sant-angelo)
 *   Caffe/Te       -> caffete       (invece di caffe-te)
 *   SEO/GEO — 2026 -> seogeo-2026   (invece di seo-geo-2026)
 *
 * In italiano l'apostrofo e' ovunque (dell'arte, l'azienda, un po'), quindi
 * non e' un caso di confine: e' il caso normale. Gli accenti invece li tratta
 * gia' bene, traslitterando alla lettera senza accento (citta', perche', piu').
 */
class Slug
{
    /**
     * Caratteri che SEPARANO due parole e che Str::slug eliminerebbe,
     * appiccicandole. Diventano spazi prima della conversione.
     */
    private const SEPARATORI = ["'", '’', '‘', '/', '\\', '|', '—', '–', '_', '.', ':', ';', ','];

    /**
     * Sostituzioni con un significato, non con un carattere.
     * "Attivita & Servizi" -> attivita-e-servizi, non attivita-servizi.
     */
    private const DIZIONARIO = ['@' => 'at', '&' => 'e', '+' => 'piu'];

    /**
     * Lo slug di un testo. Stringa vuota se non ne resta nulla di utilizzabile
     * (un titolo tutto in giapponese, o soli emoji): meglio vuoto, perche' il
     * form lo segnala come obbligatorio e chi scrive ne sceglie uno, che uno
     * slug inventato e illeggibile.
     */
    public static function da(?string $testo): string
    {
        $testo = str_replace(self::SEPARATORI, ' ', (string) $testo);

        return Str::slug($testo, '-', 'en', self::DIZIONARIO);
    }

    /**
     * La regola di unicita' dello slug per il sito corrente.
     *
     * Senza, due nomi diversi che producono lo stesso slug ("Citta'" e
     * "Citta") arrivano al database e il redattore riceve una pagina di
     * errore SQL invece di un messaggio nel form. Vale per pagine, articoli,
     * categorie e tag: la tabella ha l'indice unico su (site_id, slug) in
     * tutti e quattro i casi.
     *
     * Il vincolo va ristretto al sito a mano: la regola `unique` di Laravel
     * interroga la TABELLA, non il modello, quindi il global scope di
     * BelongsToSite non la tocca. Senza il `where`, un tag "novita" su un
     * sito impedirebbe di crearne uno con lo stesso nome su tutti gli altri.
     *
     * Il parametro si chiama `$rule` e non `$regola` perche' Filament inietta
     * le dipendenze delle closure PER NOME: con un nome diverso non trova
     * cosa passare e solleva un BindingResolutionException opaco.
     */
    public static function regolaUnica(Unique $rule): Unique
    {
        return $rule->where('site_id', BelongsToSite::currentSiteId());
    }
}
