<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Page;
use App\Models\Post;
use App\Models\Site;
use App\Models\Tag;

/**
 * La sitemap del sito.
 *
 * Stava dentro SitePageController, che pero' e' il controller delle PAGINE:
 * appena la sitemap ha dovuto elencare anche articoli e archivi, quel nome ha
 * smesso di dire la verita'. Il percorso dell'API non cambia.
 *
 * Le URL portano SEMPRE lo slash finale: Astro genera <slug>/index.html,
 * quindi il server risponde 200 solo su quella forma e 301 sull'altra. Una
 * sitemap che elenca URL che redirigono fa pagare un salto in piu' a ogni
 * passaggio del crawler, ed e' un difetto che non si vede guardando il sito.
 */
class SiteSitemapController extends Controller
{
    public function __invoke(Site $site): array
    {
        $base = 'https://' . $site->domain;
        $blog = $site->baseBlog();

        $urls = $this->pagine($base)
            ->concat($this->articoli($base, $blog))
            ->concat($this->archivi($base, $blog))
            ->values();

        return [
            'site' => $site->domain,
            'generated_at' => now()->toIso8601String(),
            'urls' => $urls,
        ];
    }

    private function pagine(string $base)
    {
        return Page::query()
            ->where('status', 'published')
            ->get()
            ->reject(fn (Page $p) => (bool) ($p->seo['noindex'] ?? false))
            ->map(fn (Page $p) => [
                'loc' => $base . ($p->is_home ? '/' : '/' . $p->slug . '/'),
                'lastmod' => $p->updated_at?->toIso8601String(),
                'changefreq' => $p->seo['sitemap_change_freq'] ?? 'weekly',
                'priority' => $p->seo['sitemap_priority'] ?? ($p->is_home ? '1.0' : '0.7'),
            ]);
    }

    private function articoli(string $base, string $blog)
    {
        $articoli = Post::query()->pubblicati()->get()
            ->reject(fn (Post $p) => (bool) ($p->seo['noindex'] ?? false));

        if ($articoli->isEmpty()) {
            return collect();
        }

        // L'indice del blog esiste solo se c'e' almeno un articolo: una pagina
        // vuota nella sitemap e' una pagina sottile che i motori segnalano.
        $indice = collect([[
            'loc' => "{$base}/{$blog}/",
            'lastmod' => $articoli->max('updated_at')?->toIso8601String(),
            'changefreq' => 'daily',
            'priority' => '0.8',
        ]]);

        return $indice->concat($articoli->map(fn (Post $p) => [
            'loc' => "{$base}/{$blog}/{$p->slug}/",
            'lastmod' => $p->updated_at?->toIso8601String(),
            'changefreq' => $p->seo['sitemap_change_freq'] ?? 'monthly',
            'priority' => $p->seo['sitemap_priority'] ?? '0.6',
        ]));
    }

    /**
     * Archivi di categoria e tag.
     *
     * Solo quelli con almeno un articolo pubblicato: un termine resta in
     * tabella anche quando l'ultimo articolo che lo usava torna bozza, e un
     * archivio vuoto e' una pagina sottile. Gli articoli mostrano solo i
     * termini che hanno addosso, quindi i due elenchi concordano da soli.
     */
    private function archivi(string $base, string $blog)
    {
        $conArticoli = fn ($q) => $q->whereHas('posts', fn ($p) => $p->pubblicati());

        $categorie = Category::query()->tap($conArticoli)->get()
            ->map(fn (Category $c) => [
                'loc' => "{$base}/{$blog}/categoria/{$c->slug}/",
                'lastmod' => null,
                'changefreq' => 'weekly',
                'priority' => '0.4',
            ]);

        $tag = Tag::query()->tap($conArticoli)->get()
            ->map(fn (Tag $t) => [
                'loc' => "{$base}/{$blog}/tag/{$t->slug}/",
                'lastmod' => null,
                'changefreq' => 'weekly',
                'priority' => '0.3',
            ]);

        return $categorie->concat($tag);
    }
}
