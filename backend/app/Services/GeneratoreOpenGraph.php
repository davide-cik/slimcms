<?php

namespace App\Services;

use App\Models\Site;
use Imagick;
use ImagickDraw;
use ImagickPixel;

/**
 * Genera l'immagine Open Graph di una pagina.
 *
 * RASTER, non SVG: i social non accettano SVG per og:image, e' un vincolo
 * loro. Si disegna con ImagickDraw e font TTF invece di rasterizzare un SVG
 * perche' servono le METRICHE del testo per andare a capo: un titolo lungo
 * altrimenti esce dal riquadro, e i titoli lunghi sono la norma.
 *
 * IMPIANTO DELLA PAGINA
 *
 * La tela e' verticale (1200x1600 di default, configurabile per sito) perche'
 * su Instagram il verticale e' nativo. Ma Facebook e LinkedIn NON riscalano:
 * ritagliano al centro in rapporto 1.91:1. Quindi tutto cio' che deve
 * sopravvivere — titolo, payoff, invito all'azione — sta dentro la FASCIA
 * SICURA centrale, e sopra e sotto restano solo elementi sacrificabili.
 *
 *   +----------------------+  <- tagliata su Facebook e LinkedIn
 *   |  nome del sito       |
 *   +======================+  <- inizio fascia sicura (1.91:1)
 *   |  TITOLO              |
 *   |  payoff              |
 *   |  [ invito ]          |
 *   +======================+  <- fine fascia sicura
 *   |  dati legali         |
 *   +----------------------+  <- tagliata su Facebook e LinkedIn
 */
class GeneratoreOpenGraph
{
    public const LARGHEZZA_DEFAULT = 1200;
    public const ALTEZZA_DEFAULT = 1600;

    /** Rapporto a cui Facebook, LinkedIn e WhatsApp ritagliano. */
    public const RAPPORTO_RITAGLIO = 1.91;

    private const MARGINE = 90;

    public function __construct(
        private readonly ?string $fontTitolo = null,
        private readonly ?string $fontTesto = null,
    ) {}

    private function fontTitolo(): string
    {
        return $this->fontTitolo ?? resource_path('fonts/space-grotesk.ttf');
    }

    private function fontTesto(): string
    {
        return $this->fontTesto ?? resource_path('fonts/source-serif.ttf');
    }

    /** @return array{larghezza:int, altezza:int} */
    public function dimensioni(Site $site): array
    {
        $c = $site->og_config ?? [];

        return [
            'larghezza' => max(600, min(2400, (int) ($c['larghezza'] ?? self::LARGHEZZA_DEFAULT))),
            'altezza' => max(600, min(2400, (int) ($c['altezza'] ?? self::ALTEZZA_DEFAULT))),
        ];
    }

    /**
     * Fascia che sopravvive al ritaglio: rapporto 1.91:1 centrato.
     *
     * @return array{alto:int, altezza:int}
     */
    public function fasciaSicura(int $larghezza, int $altezza): array
    {
        $altezzaFascia = (int) round(min($altezza, $larghezza / self::RAPPORTO_RITAGLIO));

        return [
            'alto' => (int) round(($altezza - $altezzaFascia) / 2),
            'altezza' => $altezzaFascia,
        ];
    }

    /** PNG dell'immagine Open Graph. */
    public function png(Site $site, string $titolo): string
    {
        ['larghezza' => $w, 'altezza' => $h] = $this->dimensioni($site);
        $c = $site->og_config ?? [];
        $tema = $site->theme ?? [];

        $sfondo = $this->colore($tema['carta'] ?? null, '#f4f4f1');
        $inchiostro = $this->colore($tema['inchiostro'] ?? null, '#16181c');
        $segnale = $this->colore($tema['segnale'] ?? null, '#0f6b4a');
        $tenue = $this->colore($tema['inchiostro_tenue'] ?? null, '#575d68');

        $im = new Imagick();
        $im->newImage($w, $h, new ImagickPixel($sfondo));
        $im->setImageFormat('png');

        $fascia = $this->fasciaSicura($w, $h);
        $utile = $w - 2 * self::MARGINE;

        // --- fuori dalla fascia: elementi sacrificabili ---------------------
        $this->testo($im, $site->name ?? $site->domain, self::MARGINE, (int) ($fascia['alto'] / 2),
            $this->fontTitolo(), 34, $segnale, Imagick::GRAVITY_NORTHWEST);

        $legale = trim((string) ($c['legale'] ?? ''));
        if ($legale !== '') {
            $y = $fascia['alto'] + $fascia['altezza'] + (int) (($h - $fascia['alto'] - $fascia['altezza']) / 2);
            $this->testo($im, $legale, self::MARGINE, $y, $this->fontTesto(), 24, $tenue, Imagick::GRAVITY_NORTHWEST);
        }

        // --- dentro la fascia: cio' che deve sopravvivere -------------------
        // Il titolo cerca il corpo piu' grande che stia in tre righe: cosi' un
        // titolo corto riempie la tela e uno lungo resta leggibile, senza che
        // nessuno dei due debba essere accorciato a mano.
        [$righeTitolo, $corpo] = $this->adattaTitolo($im, $titolo, $utile, 96, 52, 3);
        $interlinea = (int) round($corpo * 1.18);
        $altezzaTitolo = count($righeTitolo) * $interlinea;

        $payoff = trim((string) ($c['payoff'] ?? ''));
        $cta = trim((string) ($c['cta'] ?? 'Visita il nostro sito'));

        $righePayoff = $payoff !== '' ? $this->spezza($im, $payoff, $this->fontTesto(), 38, $utile, 2) : [];
        $altezzaPayoff = count($righePayoff) * 48;
        $altezzaCta = 96;

        $totale = $altezzaTitolo + ($altezzaPayoff ? $altezzaPayoff + 30 : 0) + $altezzaCta + 40;
        $y = $fascia['alto'] + (int) round(($fascia['altezza'] - $totale) / 2);

        foreach ($righeTitolo as $riga) {
            $this->testo($im, $riga, self::MARGINE, $y, $this->fontTitolo(), $corpo, $inchiostro, Imagick::GRAVITY_NORTHWEST);
            $y += $interlinea;
        }

        if ($righePayoff !== []) {
            $y += 30;
            foreach ($righePayoff as $riga) {
                $this->testo($im, $riga, self::MARGINE, $y, $this->fontTesto(), 38, $tenue, Imagick::GRAVITY_NORTHWEST);
                $y += 48;
            }
        }

        $y += 40;
        $this->invito($im, $cta, $site->domain ?? '', self::MARGINE, $y, $segnale);

        return $im->getImageBlob();
    }

    /** Versione ritagliata come la mostrerebbero Facebook e LinkedIn. */
    public function pngRitagliato(Site $site, string $titolo): string
    {
        $im = new Imagick();
        $im->readImageBlob($this->png($site, $titolo));

        ['larghezza' => $w, 'altezza' => $h] = $this->dimensioni($site);
        $fascia = $this->fasciaSicura($w, $h);

        $im->cropImage($w, $fascia['altezza'], 0, $fascia['alto']);
        $im->setImagePage(0, 0, 0, 0);
        $im->setImageFormat('png');

        return $im->getImageBlob();
    }

    /**
     * Cerca il corpo piu' grande con cui il titolo sta nel numero di righe
     * consentito. Scendere di corpo e' meglio che troncare: un titolo tagliato
     * a meta' e' peggio di uno scritto piu' piccolo.
     *
     * @return array{0: array<string>, 1: int}
     */
    private function adattaTitolo(Imagick $im, string $titolo, int $larghezza, int $max, int $min, int $righeMax): array
    {
        for ($corpo = $max; $corpo >= $min; $corpo -= 4) {
            $righe = $this->spezza($im, $titolo, $this->fontTitolo(), $corpo, $larghezza, $righeMax + 1);

            if (count($righe) <= $righeMax) {
                return [$righe, $corpo];
            }
        }

        return [$this->spezza($im, $titolo, $this->fontTitolo(), $min, $larghezza, $righeMax), $min];
    }

    /**
     * Va a capo sulle parole in base alla larghezza REALE del testo reso.
     *
     * @return array<string>
     */
    private function spezza(Imagick $im, string $testo, string $font, int $corpo, int $larghezza, int $righeMax): array
    {
        $d = new ImagickDraw();
        $d->setFont($font);
        $d->setFontSize($corpo);

        $righe = [];
        $corrente = '';

        foreach (preg_split('/\s+/u', trim($testo), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $parola) {
            $prova = $corrente === '' ? $parola : $corrente . ' ' . $parola;

            if ($this->larghezza($im, $d, $prova) <= $larghezza || $corrente === '') {
                $corrente = $prova;

                continue;
            }

            $righe[] = $corrente;
            $corrente = $parola;

            if (count($righe) >= $righeMax) {
                return $righe;
            }
        }

        if ($corrente !== '') {
            $righe[] = $corrente;
        }

        return array_slice($righe, 0, $righeMax);
    }

    private function larghezza(Imagick $im, ImagickDraw $d, string $testo): float
    {
        return (float) ($im->queryFontMetrics($d, $testo)['textWidth'] ?? 0);
    }

    private function testo(Imagick $im, string $testo, int $x, int $y, string $font, int $corpo, string $colore, int $gravita): void
    {
        $d = new ImagickDraw();
        $d->setFont($font);
        $d->setFontSize($corpo);
        $d->setFillColor(new ImagickPixel($colore));
        $d->setGravity($gravita);
        $im->annotateImage($d, $x, $y, 0, $testo);
    }

    /** L'invito all'azione: pillola piena, il pezzo piu' visibile della fascia. */
    private function invito(Imagick $im, string $etichetta, string $dominio, int $x, int $y, string $colore): void
    {
        $d = new ImagickDraw();
        $d->setFont($this->fontTitolo());
        $d->setFontSize(34);

        $metriche = $im->queryFontMetrics($d, $etichetta);
        $larghezza = (int) round($metriche['textWidth']) + 80;
        $altezza = 76;

        $p = new ImagickDraw();
        $p->setFillColor(new ImagickPixel($colore));
        $p->roundRectangle($x, $y, $x + $larghezza, $y + $altezza, 38, 38);
        $im->drawImage($p);

        $this->testo($im, $etichetta, $x + 40, $y + 24, $this->fontTitolo(), 34, '#ffffff', Imagick::GRAVITY_NORTHWEST);

        if ($dominio !== '') {
            $this->testo($im, $dominio, $x + $larghezza + 32, $y + 26, $this->fontTesto(), 30, $colore, Imagick::GRAVITY_NORTHWEST);
        }
    }

    /** Solo esadecimali: qualunque altra cosa e' un errore di configurazione. */
    private function colore(?string $valore, string $default): string
    {
        return is_string($valore) && preg_match('/^#[0-9a-f]{3,8}$/i', $valore) ? $valore : $default;
    }
}
