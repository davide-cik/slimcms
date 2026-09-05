<?php

namespace App\Services;

use App\Models\Site;

/**
 * Genera una favicon SVG con le iniziali del sito.
 *
 * SVG e non PNG di proposito: pesa poche centinaia di byte, resta nitida a
 * qualsiasi dimensione senza produrre sei varianti, e non richiede GD ne'
 * Imagick lato build. I browser moderni la accettano come favicon; per quelli
 * che non lo fanno resta il fallback ICO del server.
 *
 * I colori vengono dal tema del sito, cosi' la favicon non stona con il sito
 * a cui appartiene.
 */
class GeneratoreFavicon
{
    /** Colori usati quando il tema del sito non li definisce. */
    private const SFONDO_DEFAULT = '#0f6b4a';
    private const TESTO_DEFAULT = '#ffffff';

    /**
     * Iniziali da mostrare.
     *
     * Priorita': quelle scelte a mano, altrimenti derivate dal nome. La
     * derivazione prende l'iniziale delle prime due parole significative:
     * "Studio Rossi" -> SR, "Il Girasole" -> G (gli articoli si saltano,
     * altrimenti meta' dei siti italiani avrebbero una I).
     */
    public function iniziali(Site $site): string
    {
        if (filled($site->favicon_initials)) {
            return mb_strtoupper(mb_substr(trim($site->favicon_initials), 0, 3));
        }

        return $this->dalNome($site->name ?? $site->domain ?? '?');
    }

    public function dalNome(string $nome): string
    {
        // Articoli e preposizioni: non sono l'identita' del sito.
        // Italiano e inglese: molti nomi di siti mescolano le due lingue.
        $ignorate = [
            'il', 'lo', 'la', 'i', 'gli', 'le', 'un', 'uno', 'una',
            'di', 'da', 'del', 'dello', 'della', 'dei', 'delle', 'e', 'ed', 'a', 'al',
            'the', 'of', 'is', 'and', 'for', 'an', 'to', 'in', 'on',
        ];

        $parole = collect(preg_split('/[\s\-_.]+/u', trim($nome), -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn (string $p) => preg_replace('/[^\p{L}\p{N}]/u', '', $p))
            ->filter(fn (?string $p) => filled($p))
            ->reject(fn (string $p) => in_array(mb_strtolower($p), $ignorate, true))
            ->values();

        if ($parole->isEmpty()) {
            return mb_strtoupper(mb_substr(preg_replace('/[^\p{L}\p{N}]/u', '', $nome) ?: '?', 0, 1));
        }

        if ($parole->count() === 1) {
            return mb_strtoupper(mb_substr($parole[0], 0, 1));
        }

        return mb_strtoupper(mb_substr($parole[0], 0, 1) . mb_substr($parole[1], 0, 1));
    }

    /** SVG della favicon, pronto da scrivere su file o servire. */
    public function svg(Site $site): string
    {
        $iniziali = $this->iniziali($site);
        $tema = $site->theme ?? [];

        $sfondo = $this->colore($tema['segnale'] ?? $tema['primario'] ?? null, self::SFONDO_DEFAULT);
        $testo = $this->colore($tema['favicon_testo'] ?? null, self::TESTO_DEFAULT);

        // La dimensione del carattere scende al crescere delle lettere,
        // altrimenti tre iniziali escono dal riquadro.
        $dimensione = match (mb_strlen($iniziali)) {
            1 => 62,
            2 => 44,
            default => 32,
        };

        $etichetta = htmlspecialchars($site->name ?? $site->domain ?? '', ENT_QUOTES | ENT_XML1);
        $lettere = htmlspecialchars($iniziali, ENT_QUOTES | ENT_XML1);

        return <<<SVG
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" role="img" aria-label="{$etichetta}">
          <title>{$etichetta}</title>
          <rect width="100" height="100" rx="20" fill="{$sfondo}"/>
          <text x="50" y="50" fill="{$testo}"
                font-family="system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif"
                font-size="{$dimensione}" font-weight="700" letter-spacing="-1"
                text-anchor="middle" dominant-baseline="central">{$lettere}</text>
        </svg>
        SVG;
    }

    /**
     * La favicon come file ICO vero, in byte.
     *
     * Serve perche' un browser che non trova un `<link rel="icon">` utile — e
     * anche molti che lo trovano, piu' quasi tutti i crawler — chiede
     * `/favicon.ico` alla radice del dominio, senza guardare l'HTML. Finche'
     * quel file non c'era, ogni visita produceva un 404: erano i primi che il
     * nostro stesso monitoraggio ha registrato su slimcms.it.
     *
     * Tre dimensioni in un file solo (16, 32, 48): e' cosa sa fare il formato
     * ICO, e il browser prende quella che gli serve senza riscalare.
     *
     * La sorgente e' sempre una sola — il file caricato se c'e', altrimenti
     * l'SVG generato — cosi' l'icona nella scheda e quella nei preferiti non
     * possono raccontare due cose diverse.
     */
    public function ico(Site $site): string
    {
        $sorgente = $this->sorgente($site);

        $ico = new \Imagick();

        foreach ([16, 32, 48] as $lato) {
            $livello = new \Imagick();
            $livello->setBackgroundColor(new \ImagickPixel('transparent'));
            $livello->readImageBlob($sorgente);
            $livello->setImageFormat('png32');
            // Il riquadro puo' non essere quadrato se il file l'ha caricato
            // il cliente: si riempie il quadrato senza deformare, che e' cio'
            // che ci si aspetta da un'icona.
            $livello->cropThumbnailImage($lato, $lato);
            $ico->addImage($livello);
        }

        $ico->setFormat('ico');

        return $ico->getImagesBlob();
    }

    /**
     * I byte da cui rasterizzare: il file caricato dal cliente, oppure l'SVG
     * generato.
     *
     * L'SVG viene reso a 256 pixel e poi rimpicciolito: lasciandolo alla sua
     * misura naturale (il viewBox, 100 pixel) le iniziali a 16 pixel
     * uscirebbero impastate.
     */
    private function sorgente(Site $site): string
    {
        $caricata = $this->fileCaricato($site);

        if ($caricata !== null) {
            return $caricata;
        }

        return str_replace(
            '<svg xmlns=',
            '<svg width="256" height="256" xmlns=',
            $this->svg($site)
        );
    }

    /**
     * Il file caricato dal cliente, se c'e' ed e' ancora sul disco.
     *
     * Sta su un disco privato: non e' raggiungibile da fuori e non lo deve
     * essere. Passa di qui, viene convertito, ed esce come file del sito.
     */
    public function fileCaricato(Site $site): ?string
    {
        if (blank($site->favicon_path)) {
            return null;
        }

        $disco = \Illuminate\Support\Facades\Storage::disk(config('filament.default_filesystem_disk', 'local'));

        // Un percorso rimasto nel database dopo che il file e' sparito non e'
        // un motivo per rispondere 500: si torna a quella generata, che c'e'
        // sempre.
        return $disco->exists($site->favicon_path) ? $disco->get($site->favicon_path) : null;
    }

    /** L'SVG da pubblicare, o null se il cliente ha caricato un'immagine non vettoriale. */
    public function svgPubblicabile(Site $site): ?string
    {
        if (blank($site->favicon_path)) {
            return $this->svg($site);
        }

        // Un PNG non diventa un SVG: in quel caso il sito dichiara solo
        // l'ICO. Meglio una sola icona giusta che due che si contraddicono.
        return str_ends_with(strtolower($site->favicon_path), '.svg')
            ? $this->fileCaricato($site)
            : null;
    }

    /** Accetta solo colori esadecimali: qualunque altra cosa finirebbe dentro l'SVG. */
    private function colore(?string $valore, string $default): string
    {
        return is_string($valore) && preg_match('/^#[0-9a-f]{3,8}$/i', $valore)
            ? $valore
            : $default;
    }
}
