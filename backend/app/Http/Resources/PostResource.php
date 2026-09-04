<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\Concerns\ConMedia;

class PostResource extends JsonResource
{
    use ConMedia;

    public function toArray(Request $request): array
    {
        $seo = $this->seo ?? [];

        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'excerpt' => $this->excerpt ?? ($seo['meta_description'] ?? null),
            // La copertina viene dalla libreria media, non da una stringa:
            // cosi' il frontend riceve anche alt e varianti gia' pronte.
            'featured_image' => $this->mediaPubblico($this->getFirstMedia('copertina')),
            'status' => $this->status,
            'published_at' => $this->publish_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'author' => $this->whenLoaded('author', fn () => [
                'name' => $this->author?->name,
            ]),
            'categories' => $this->whenLoaded('categories', fn () => $this->categories
                ->map(fn ($c) => ['name' => $c->name, 'slug' => $c->slug])->values()),
            // Nome e slug, come le categorie: lo slug serve alla pagina
            // d'archivio, il nome a mostrarlo.
            'tags' => $this->whenLoaded('tags', fn () => $this->tags
                ->map(fn ($t) => ['name' => $t->name, 'slug' => $t->slug])->values()),
            'blocks' => $this->blocks ?? [],

            'seo' => [
                'meta_title' => $seo['meta_title'] ?? $this->title,
                'meta_description' => $seo['meta_description'] ?? $this->excerpt,
                'canonical_url' => $seo['canonical_url'] ?? null,
                'noindex' => (bool) ($seo['noindex'] ?? false),
                'og_title' => $seo['og_title'] ?? $seo['meta_title'] ?? $this->title,
                'og_description' => $seo['og_description'] ?? $seo['meta_description'] ?? $this->excerpt,
                'og_image' => $seo['og_image'] ?? $this->getFirstMedia('copertina')?->getUrl('web'),
            ],

            'geo' => [
                'structured_summary' => $seo['structured_summary'] ?? null,
                'key_facts' => array_values($seo['key_facts'] ?? []),
                'source_attribution' => [
                    'author' => $this->author?->name,
                    'published_at' => $this->publish_at?->toIso8601String(),
                    'updated_at' => $this->updated_at?->toIso8601String(),
                ],
            ],

            'aeo' => [
                'direct_answer' => $seo['direct_answer'] ?? null,
                'faq' => array_values($seo['faq_block'] ?? []),
                // Un articolo di blog e' un Article salvo diversa indicazione.
                'schema_type' => $seo['schema_type'] ?? 'Article',
            ],
        ];
    }
}
