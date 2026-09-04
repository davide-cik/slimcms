<?php

namespace App\Http\Middleware;

use App\Models\Site;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autorizza un token Sanctum ad accedere a UN sito specifico, e inizializza
 * il contesto multitenant per la richiesta.
 *
 * Perche' serve: il lookup di Site nella route-model-binding avviene con la
 * tenancy NON inizializzata, quindi il global scope di stancl e' inerte e
 * risolverebbe felicemente qualunque sito. Senza questo controllo, un solo
 * token del build worker leggerebbe i contenuti di tutti i clienti.
 *
 * Due livelli di abilita':
 *   site:<id>  -> token legato a un singolo sito (caso normale)
 *   sites:*    -> token di piattaforma, per il worker di build che rigenera
 *                 qualsiasi sito. Da emettere con parsimonia.
 */
class EnsureTokenCanAccessSite
{
    public function handle(Request $request, Closure $next): Response
    {
        $site = $request->route('site');

        if (! $site instanceof Site) {
            abort(404);
        }

        $utente = $request->user();

        if ($utente === null) {
            abort(401, 'Token mancante.');
        }

        $consentito = $utente->tokenCan('sites:*')
            || $utente->tokenCan('site:' . $site->getKey());

        if (! $consentito) {
            abort(403, 'Questo token non e\' abilitato per il sito richiesto.');
        }

        // Ordine obbligato, come in ResolveSiteFromDomain: prima si conosce il
        // sito, poi si inizializza il tenant, poi si fissa il sito corrente.
        if ($site->tenant !== null) {
            tenancy()->initialize($site->tenant);
        }

        $site->useAsCurrent();

        return $next($request);
    }
}
