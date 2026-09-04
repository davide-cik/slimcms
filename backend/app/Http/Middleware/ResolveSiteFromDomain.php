<?php

namespace App\Http\Middleware;

use App\Models\Site;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Risolve il sito dal dominio della richiesta e inizializza il contesto
 * multitenant per il resto del ciclo di vita.
 *
 * ORDINE OBBLIGATO, non riordinare:
 *
 *   1. cercare il Site per host, con lo scope tenant DISATTIVO
 *   2. inizializzare il tenant a partire da $site->tenant
 *   3. impostare il sito corrente
 *
 * Il passo 1 deve venire prima del 2: il global scope di stancl e' inerte
 * finche' tenancy()->initialized e' false, quindi la ricerca vede tutte le
 * righe. Se si inizializzasse il tenant per primo, la query su Site sarebbe
 * gia' filtrata per un tenant che non si conosce ancora, e la riga che serve
 * per SCOPRIRE quel tenant non sarebbe trovabile.
 *
 * Fonte di verita' per la mappa dominio -> sito e' la colonna sites.domain.
 * NON si usa la tabella "domains" di stancl: quella mappa dominio -> tenant,
 * un livello troppo grossolano, dato che un tenant puo' avere piu' siti con
 * domini diversi. Due fonti di verita' sullo stesso dato produrrebbero
 * risoluzioni sbagliate silenziose.
 */
class ResolveSiteFromDomain
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = $this->normalizeHost($request->getHost());

        // 1. Lookup non scoped (tenancy non ancora inizializzata).
        $site = Site::query()
            ->where('domain', $host)
            ->with('tenant')
            ->first();

        if ($site === null) {
            abort(404, "Nessun sito configurato per il dominio {$host}.");
        }

        if ($site->tenant === null) {
            abort(500, "Il sito {$host} non ha un tenant associato.");
        }

        // 2. Da qui in poi ogni query su Site e' filtrata per questo tenant.
        tenancy()->initialize($site->tenant);

        // 3. Da qui in poi ogni query su Page/Post/Media e' filtrata per questo sito.
        $site->useAsCurrent();

        return $next($request);
    }

    /**
     * Confronta i domini in modo prevedibile: minuscolo e senza "www.".
     * Il valore salvato in sites.domain e' quello canonico senza www.
     */
    private function normalizeHost(string $host): string
    {
        $host = strtolower($host);

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }
}
