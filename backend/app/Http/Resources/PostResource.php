<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $seo = $this->seo ?? [];

        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'excerpt' => $this->excerpt ?? ($seo['meta_description'] ?? null),
            'featured_image' => $this->featured_image_path,
            'status' => $this->status,
            'published_at' => $this->publish_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'author' => $this->whenLoaded('author', fn () => [
                'name' => $this->author?->name,
            ]),
            'categories' => $this->whenLoaded('categories', fn () => $this->categories
                ->map(fn ($c) => ['name' => $c->name, 'slug' => $c->slug])->values()),
            'tags' => array_values($this->tags ?? []),
            'blocks' => $this->blocks ?? [],

            'seo' => [
                'meta_title' => $seo['meta_title'] ?? $this->title,
                'meta_description' => $seo['meta_description'] ?? $this->excerpt,
                'canonical_url' => $seo['canonical_url'] ?? null,
                'noindex' => (bool) ($seo['noindex'] ?? false),
                'og_title' => $seo['og_title'] ?? $seo['meta_title'] ?? $this->title,
                'og_description' => $seo['og_description'] ?? $seo['meta_description'] ?? $this->excerpt,
                'og_image' => $seo['og_image'] ?? $this->featured_image_path,
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
