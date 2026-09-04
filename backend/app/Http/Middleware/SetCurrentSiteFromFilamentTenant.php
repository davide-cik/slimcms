<?php

namespace App\Http\Middleware;

use App\Models\Site;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dentro il pannello Filament il sito non si risolve dal dominio ma dal
 * selettore di tenant. Questo middleware allinea il binding 'currentSite'
 * a quel tenant, cosi' i global scope di BelongsToSite filtrano sul sito
 * giusto anche in /admin.
 *
 * Senza questo, dentro il pannello 'currentSite' non sarebbe mai valorizzato:
 * le query su Page tornerebbero TUTTE le pagine di TUTTI i siti, e la
 * creazione di una pagina solleverebbe l'eccezione di BelongsToSite.
 */
class SetCurrentSiteFromFilamentTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $site = \Filament\Facades\Filament::getTenant();

        if ($site instanceof Site) {
            $site->useAsCurrent();

            // Allinea anche il livello tenant, cosi' le query su Site
            // restano confinate al cliente proprietario.
            if (! tenancy()->initialized && $site->tenant !== null) {
                tenancy()->initialize($site->tenant);
            }
        }

        return $next($request);
    }
}
