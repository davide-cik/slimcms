<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\Concerns\ConMedia;

/**
 * Forma JSON di una pagina, cosi' come la consuma il worker di build Astro.
 *
 * I campi SEO/GEO/AEO sono esposti separati invece che come blob "seo",
 * cosi' il contratto e' esplicito: se domani un campo cambia nome nel DB,
 * il frontend non se ne accorge.
 */
class PageResource extends JsonResource
{
    use ConMedia;

    public function toArray(Request $request): array
    {
        $seo = $this->seo ?? [];

        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            // Quale pagina sta sulla radice del dominio lo decide questo
            // flag, non lo slug: Astro non deve piu' indovinare.
            'is_home' => (bool) $this->is_home,
            'colonne' => (int) ($this->colonne ?: 1),
            'status' => $this->status,
            'published_at' => $this->publish_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            // I blocchi escono con le immagini gia' risolte: il frontend
            // riceve url e alt, non uuid da ricomporre con l'elenco media.
            'blocks' => $this->blocchiRisolti(),

            // Le immagini della pagina, con alt e varianti. I blocchi galleria
            // vi fanno riferimento per id.
            'media' => $this->getMedia('immagini')
                ->map(fn ($m) => $this->mediaPubblico($m))
                ->values(),

            'seo' => [
                'meta_title' => $seo['meta_title'] ?? $this->title,
                'meta_description' => $seo['meta_description'] ?? null,
                'canonical_url' => $seo['canonical_url'] ?? null,
                'noindex' => (bool) ($seo['noindex'] ?? false),
                'og_title' => $seo['og_title'] ?? $seo['meta_title'] ?? $this->title,
                'og_description' => $seo['og_description'] ?? $seo['meta_description'] ?? null,
                'og_image' => $seo['og_image'] ?? null,
            ],

            // GEO: materiale per i motori generativi.
            'geo' => [
                'structured_summary' => $seo['structured_summary'] ?? null,
                'key_facts' => array_values($seo['key_facts'] ?? []),
                'source_attribution' => [
                    'published_at' => $this->publish_at?->toIso8601String(),
                    'updated_at' => $this->updated_at?->toIso8601String(),
                ],
            ],

            // AEO: risposta diretta e FAQ, da cui Astro genera FAQPage.
            'aeo' => [
                'direct_answer' => $seo['direct_answer'] ?? null,
                'faq' => array_values($seo['faq_block'] ?? []),
                'schema_type' => $seo['schema_type'] ?? 'Article',
            ],
        ];
    }

    /**
     * Sostituisce nei blocchi gli uuid dei media con la loro forma pubblica.
     *
     * I blocchi salvano un riferimento, non il file: la stessa immagine puo'
     * comparire in piu' blocchi, e l'alt vive sul file. Risolvere qui evita
     * che ogni consumatore debba incrociare `blocks` con `media` da solo,
     * sbagliando in modo diverso ogni volta.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function blocchiRisolti(): array
    {
        $perUuid = $this->getMedia('immagini')->keyBy('uuid');

        $risolvi = function ($valore) use (&$risolvi, $perUuid) {
            if (is_string($valore)) {
                return $perUuid->has($valore)
                    ? $this->mediaPubblico($perUuid->get($valore))
                    : $valore;
            }

            if (is_array($valore)) {
                return array_map($risolvi, $valore);
            }

            return $valore;
        };

        return array_map(
            function (array $blocco) use ($risolvi): array {
                $dati = $risolvi($blocco['data'] ?? []);

                // Un blocco modulo porta l'id del modulo; il sito ha bisogno
                // dei suoi campi per disegnarlo. Si risolvono qui come le
                // immagini, per la stessa ragione: Astro riceve tutto cio'
                // che gli serve e non fa una seconda domanda all'API.
                if (($blocco['type'] ?? null) === 'modulo_contatto') {
                    $dati['modulo'] = $this->moduloRisolto($dati['modulo_id'] ?? null);
                    unset($dati['modulo_id']);
                }

                return ['type' => $blocco['type'] ?? null, 'data' => $dati];
            },
            array_values($this->blocks ?? [])
        );
    }

    /**
     * Il modulo di un blocco, con i suoi campi.
     *
     * Se non c'e' o non e' attivo si ricade su quello di contatto del sito, e
     * se non esiste nemmeno quello si torna `null`: il sito disegna comunque
     * i tre campi di sempre, invece di mostrare un modulo vuoto o di far
     * fallire la build per una configurazione incompleta.
     *
     * @return array<string, mixed>|null
     */
    protected function moduloRisolto(int|string|null $id): ?array
    {
        $modulo = filled($id)
            ? \App\Models\Modulo::query()->attivi()->find($id)
            : \App\Models\Modulo::query()->attivi()->orderBy('id')->first();

        if ($modulo === null) {
            return null;
        }

        return [
            'slug' => $modulo->slug,
            'nome' => $modulo->nome,
            'conferma' => $modulo->messaggio_conferma,
            'campi' => $modulo->campiNormalizzati(),
        ];
    }
}
