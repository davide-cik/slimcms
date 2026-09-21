<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\ConMedia;
use App\Http\Resources\Concerns\RisolveBlocchi;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un prodotto come lo riceve la build di Astro.
 *
 * `disponibile` e non `scorte`: quanti pezzi ha in magazzino un cliente non
 * e' un dato da pubblicare nell'HTML di un sito.
 */
class ProdottoResource extends JsonResource
{
    use ConMedia;
    use RisolveBlocchi;

    public function toArray(Request $request): array
    {
        $seo = $this->seo ?? [];

        return [
            'id' => $this->id,
            'nome' => $this->nome,
            'slug' => $this->slug,
            'descrizione' => $this->descrizione,
            'prezzo' => (int) $this->prezzo,
            // Il form lo vuole piu' alto del prezzo; se un dato storto arriva
            // lo stesso, meglio nessun barrato che "prima 30, ora 39".
            'prezzo_barrato' => $this->prezzo_barrato !== null && $this->prezzo_barrato > $this->prezzo
                ? (int) $this->prezzo_barrato
                : null,
            'valuta' => 'EUR',
            'disponibile' => $this->disponibile(),
            'immagini' => $this->getMedia('immagini')
                ->map(fn ($m) => $this->mediaPubblico($m))
                ->values(),
            'blocks' => $this->blocchiRisolti(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            'seo' => [
                'meta_title' => $seo['meta_title'] ?? $this->nome,
                'meta_description' => $seo['meta_description'] ?? $this->descrizione,
                'canonical_url' => $seo['canonical_url'] ?? null,
                'noindex' => (bool) ($seo['noindex'] ?? false),
                'og_title' => $seo['og_title'] ?? $seo['meta_title'] ?? $this->nome,
                'og_description' => $seo['og_description'] ?? $seo['meta_description'] ?? $this->descrizione,
                'og_image' => $seo['og_image'] ?? null,
            ],

            'geo' => [
                'structured_summary' => $seo['structured_summary'] ?? null,
                'key_facts' => array_values($seo['key_facts'] ?? []),
            ],

            'aeo' => [
                'direct_answer' => $seo['direct_answer'] ?? null,
                'faq' => array_values($seo['faq_block'] ?? []),
                'schema_type' => 'Product',
            ],
        ];
    }
}
