<?php

namespace App\Http\Middleware;

use App\Models\Site;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Risolve il sito dal parametro di rotta `{sito}` (il dominio) e inizializza
 * il contesto multitenant.
 *
 * Perche' non dall'Host, come faceva il middleware che c'era prima: il sito
 * pubblico e' statico e vive su `<dominio-cliente>`, mentre l'API sta su
 * `manage.slimcms.it`. La chiamata parte dal browser del visitatore, quindi
 * e' **cross-origin**, e l'Host che arriva qui e' sempre quello dell'API —
 * mai quello del cliente. Con la risoluzione dall'Host questi endpoint
 * rispondevano 404 a chiunque: e' il motivo per cui non li usava nessuno.
 *
 * Servirli dal dominio del cliente richiederebbe un proxy Apache, e su
 * questa macchina `mod_proxy_http` non e' abilitato (serve root).
 *
 * Il dominio nella URL non e' un segreto e non autorizza niente: chiunque
 * puo' scrivere al form di contatto di un sito semplicemente visitandolo. La
 * difesa qui e' il rate limiting, come prima.
 *
 * ORDINE OBBLIGATO, come in EnsureTokenCanAccessSite: prima si cerca il sito con
 * lo scope tenant inerte, poi si inizializza il tenant, poi il sito corrente.
 * Invertendo, la riga che serve a SCOPRIRE il tenant sarebbe gia' filtrata
 * per un tenant che non si conosce ancora.
 */
class RisolviSitoDaParametro
{
    public function handle(Request $request, Closure $next): Response
    {
        $dominio = strtolower((string) $request->route('sito'));

        // Stessa normalizzazione dell'Host: il valore canonico in
        // sites.domain e' senza www.
        if (str_starts_with($dominio, 'www.')) {
            $dominio = substr($dominio, 4);
        }

        $site = Site::query()->where('domain', $dominio)->with('tenant')->first();

        if ($site === null || $site->tenant === null) {
            abort(404, 'Nessun sito configurato per questo dominio.');
        }

        tenancy()->initialize($site->tenant);
        $site->useAsCurrent();

        return $next($request);
    }
}
