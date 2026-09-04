<?php

use App\Http\Controllers\Api\BuildWebhookController;
use App\Http\Controllers\Api\PublicSiteController;
use App\Http\Controllers\Api\SitePageController;
use App\Http\Controllers\Api\SitePostController;
use App\Http\Resources\SiteResource;
use App\Models\Site;
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
        Route::get('sitemap', [SitePageController::class, 'sitemap']);
        Route::get('builds', [BuildWebhookController::class, 'index']);
        Route::post('builds', [BuildWebhookController::class, 'store']);
    });

Route::prefix('public')
    ->middleware(['site.domain', 'throttle:60,1'])
    ->group(function () {
        Route::get('search', [PublicSiteController::class, 'search']);
        Route::post('contact', [PublicSiteController::class, 'contact'])
            ->middleware('throttle:5,1');
    });
