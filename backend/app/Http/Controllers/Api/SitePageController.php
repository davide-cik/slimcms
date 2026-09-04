<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PageResource;
use App\Models\Page;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Endpoint letti dal worker di build Astro, non dal visitatore finale.
 *
 * Tutte le query qui sotto NON filtrano per site_id a mano: lo fa il global
 * scope di BelongsToSite, perche' EnsureTokenCanAccessSite ha gia' fissato
 * il sito corrente. Aggiungere un where('site_id', ...) sarebbe ridondante
 * e darebbe la falsa impressione che senza di esso la query sia aperta.
 */
class SitePageController extends Controller
{
    public function index(Request $request, Site $site): AnonymousResourceCollection
    {
        $pagine = Page::query()
            ->when($request->boolean('published_only', true), fn ($q) => $q->where('status', 'published'))
            ->orderBy('title')
            ->paginate($request->integer('per_page', 50));

        return PageResource::collection($pagine);
    }

    public function show(Request $request, Site $site, string $slug): PageResource
    {
        $pagina = Page::query()
            ->where('slug', $slug)
            ->when($request->boolean('published_only', true), fn ($q) => $q->where('status', 'published'))
            ->firstOrFail();

        return new PageResource($pagina);
    }

    /**
     * Dati minimi per generare sitemap.xml lato Astro: solo le pagine
     * pubblicate e non escluse dai motori.
     */
    public function sitemap(Site $site): array
    {
        $pagine = Page::query()
            ->where('status', 'published')
            ->get()
            ->reject(fn (Page $p) => (bool) ($p->seo['noindex'] ?? false))
            ->map(fn (Page $p) => [
                // Slash finale SEMPRE: Astro genera <slug>/index.html, quindi il
                // server serve 200 solo sulla forma con slash e risponde 301
                // sull'altra. Una sitemap che elenca URL che redirigono fa
                // pagare un salto in piu' a ogni passaggio del crawler.
                'loc' => 'https://' . $site->domain . ($p->slug === 'home' ? '/' : '/' . $p->slug . '/'),
                'lastmod' => $p->updated_at?->toIso8601String(),
                'changefreq' => $p->seo['sitemap_change_freq'] ?? 'weekly',
                'priority' => $p->seo['sitemap_priority'] ?? ($p->slug === 'home' ? '1.0' : '0.7'),
            ])
            ->values();

        return [
            'site' => $site->domain,
            'generated_at' => now()->toIso8601String(),
            'urls' => $pagine,
        ];
    }
}
