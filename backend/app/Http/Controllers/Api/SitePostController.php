<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PostResource;
use App\Models\Post;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Articoli del blog, letti dal worker di build.
 *
 * Come per le pagine, il filtro per sito lo applica il global scope: qui non
 * si aggiunge nessun where('site_id').
 */
class SitePostController extends Controller
{
    public function index(Request $request, Site $site): AnonymousResourceCollection
    {
        $articoli = Post::query()
            ->with(['author', 'categories', 'tags'])
            ->when($request->boolean('published_only', true), fn ($q) => $q->pubblicati())
            ->when($request->filled('category'), fn ($q) => $q->whereHas(
                'categories',
                fn ($c) => $c->where('slug', $request->string('category'))
            ))
            // Per slug come per le categorie: prima i tag erano stringhe in
            // una colonna JSON e il filtro cercava il testo esatto, quindi
            // "Performance" e "performance" erano due filtri diversi.
            ->when($request->filled('tag'), fn ($q) => $q->whereHas(
                'tags',
                fn ($t) => $t->where('slug', $request->string('tag'))
            ))
            ->orderByDesc('publish_at')
            ->paginate($request->integer('per_page', 20));

        return PostResource::collection($articoli);
    }

    public function show(Request $request, Site $site, string $slug): PostResource
    {
        $articolo = Post::query()
            ->with(['author', 'categories', 'tags'])
            ->where('slug', $slug)
            ->when($request->boolean('published_only', true), fn ($q) => $q->pubblicati())
            ->firstOrFail();

        return new PostResource($articolo);
    }
}
