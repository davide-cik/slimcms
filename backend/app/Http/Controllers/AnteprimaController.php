<?php

namespace App\Http\Controllers;

use App\Http\Resources\PageResource;
use App\Models\Page;
use App\Services\StiliDelSito;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

/**
 * L'anteprima di una pagina, anche in bozza.
 *
 * Serve perche' una bozza **non esiste** sul sito: il sito e' statico e
 * contiene solo cio' che e' pubblicato. Chi scrive deve poter vedere come
 * verra' prima di mettercela.
 *
 * Non e' una rotta del pannello Filament di proposito: l'anteprima dev'essere
 * un documento HTML intero, con i fogli di stile del sito, senza la cornice
 * dell'amministrazione intorno.
 *
 * L'autorizzazione passa dalla policy vera, non da un controllo scritto qui:
 * si fissa il pannello e il sito della pagina, e poi si chiede al Gate. Un
 * secondo controllo scritto a mano sarebbe una seconda regola da tenere
 * allineata a `PagePolicy`.
 */
class AnteprimaController extends Controller
{
    public function __invoke(int $pagina, StiliDelSito $stili): View
    {
        // Fuori dal pannello la tenancy non e' inizializzata, quindi il
        // global scope e' inerte e la pagina si trova. Il confine lo mette
        // la policy, tre righe piu' sotto.
        $page = Page::withoutSiteScope()->with('site')->findOrFail($pagina);

        abort_if($page->site === null, 404);

        Filament::setCurrentPanel('admin');
        Filament::setTenant($page->site, isQuiet: true);
        $page->site->useAsCurrent();

        Gate::authorize('view', $page);

        $dati = (new PageResource($page))->resolve();

        return view('anteprima.pagina', [
            'pagina' => $page,
            'sito' => $page->site,
            'blocchi' => $dati['blocks'] ?? [],
            'fogli' => $stili->per($page->site),
        ]);
    }
}
