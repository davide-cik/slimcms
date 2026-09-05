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

}
