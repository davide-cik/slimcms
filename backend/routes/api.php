<?php

use App\Http\Controllers\Api\BuildWebhookController;
use App\Http\Controllers\Api\FaviconController;
use App\Http\Controllers\Api\OpenGraphController;
use App\Http\Controllers\Api\PublicSiteController;
use App\Http\Controllers\Api\SitePageController;
use App\Http\Controllers\Api\SiteRedirectController;
use App\Http\Controllers\Api\SiteSitemapController;
use App\Http\Controllers\Api\SitePostController;
use App\Http\Resources\SiteResource;
use App\Models\Site;
use App\Services\MappaRouting;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API
|--------------------------------------------------------------------------
|
| Due famiglie di endpoint con modelli di autorizzazione DIVERSI, ed e'
| importante non confonderli:
|
| 1. /api/sites/{site}/...  -> letti dal WORKER DI BUILD, non dal pubblico.
|    Autenticati con token Sanctum legato a un sito preciso
|    (EnsureTokenCanAccessSite). Il sito arriva dalla URL.
|
| 2. /api/public/...        -> invocati a runtime dal BROWSER del visitatore.
|    Nessun token: il sito si risolve dall'Host (ResolveSiteFromDomain).
|    Rate limited, perche' su un endpoint pubblico e' l'unica difesa.
|
*/

Route::prefix('sites/{site}')
    ->middleware(['auth:sanctum', 'site.token'])
    ->scopeBindings()
    ->group(function () {
        Route::get('/', fn (Site $site) => new SiteResource($site));
        Route::get('pages', [SitePageController::class, 'index']);
        Route::get('pages/{slug}', [SitePageController::class, 'show']);
        Route::get('posts', [SitePostController::class, 'index']);
        Route::get('posts/{slug}', [SitePostController::class, 'show']);
        Route::get('sitemap', SiteSitemapController::class);

        // Il .htaccess gia' compilato, non le righe grezze: la regola di come
        // si traduce un redirect in configurazione Apache sta in un punto
        // solo, lato Laravel, non duplicata nel worker di build.
        Route::get('htaccess', [SiteRedirectController::class, 'htaccess']);
        // Immagini Open Graph: PNG, perche' i social non accettano SVG.
        // /favicon.ico se lo chiedono i browser da soli, senza guardare
        // l'HTML: se non c'e' e' un 404 a ogni visita.
        Route::get('favicon.ico', [FaviconController::class, 'ico']);

        Route::get('og.png', [OpenGraphController::class, 'sito']);
        Route::get('og/{slug}.png', [OpenGraphController::class, 'contenuto']);
        Route::get('builds', [BuildWebhookController::class, 'index']);
        Route::post('builds', [BuildWebhookController::class, 'store']);
    });

// Mappa dominio -> sito per l'edge (specifiche 7.2). Richiede un token di
// piattaforma (sites:*): e' l'elenco di TUTTI i clienti, non il contenuto di
// uno, quindi un token legato a un singolo sito non basta.
Route::get('routing-map', fn (MappaRouting $mappa) => response()->json(json_decode($mappa->json(), true)))
    ->middleware(['auth:sanctum', 'abilities:sites:*']);

Route::prefix('public')
    ->middleware(['site.domain', 'throttle:60,1'])
    ->group(function () {
        Route::get('search', [PublicSiteController::class, 'search']);
        Route::post('contact', [PublicSiteController::class, 'contact'])
            ->middleware('throttle:5,1');
    });
