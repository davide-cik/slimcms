<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            // Risolve il sito dall'Host: per gli endpoint pubblici a runtime.
            'site.domain' => \App\Http\Middleware\ResolveSiteFromDomain::class,
            // Verifica che il token Sanctum sia abilitato per il sito in URL:
            // per gli endpoint letti dal worker di build.
            'site.token' => \App\Http\Middleware\EnsureTokenCanAccessSite::class,
            // Sanctum fornisce le classi ma NON registra gli alias: senza
            // questi, 'abilities' su una rotta da "Target class does not exist"
            // a runtime, cioe' un 500 al posto di un controllo di sicurezza.
            'abilities' => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
            'ability' => \Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
