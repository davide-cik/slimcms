<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProdottoResource;
use App\Models\Prodotto;
use App\Models\Site;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Il catalogo, letto dal worker di build.
 *
 * Il filtro per sito lo applica il global scope, come per pagine e articoli.
 */
class SiteProdottoController extends Controller
{
    /**
     * A negozio spento un elenco vuoto, non un 404: la build di ogni sito
     * chiama questo endpoint, e un sito senza negozio deve semplicemente non
     * avere schede prodotto — non fallire.
     */
    public function index(Site $site): AnonymousResourceCollection
    {
        $prodotti = $site->negozioAttivo()
            ? Prodotto::query()->pubblicati()->with('media')->orderBy('nome')->get()
            : collect();

        return ProdottoResource::collection($prodotti);
    }

    public function show(Site $site, string $slug): ProdottoResource
    {
        abort_unless($site->negozioAttivo(), 404);

        return new ProdottoResource(
            Prodotto::query()->pubblicati()->with('media')->where('slug', $slug)->firstOrFail()
        );
    }
}
