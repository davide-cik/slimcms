<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Forma JSON di una pagina, cosi' come la consuma il worker di build Astro.
 *
 * I campi SEO/GEO/AEO sono esposti separati invece che come blob "seo",
 * cosi' il contratto e' esplicito: se domani un campo cambia nome nel DB,
 * il frontend non se ne accorge.
 */
class PageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $seo = $this->seo ?? [];

        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'status' => $this->status,
            'published_at' => $this->publish_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'blocks' => $this->blocks ?? [],

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
}
