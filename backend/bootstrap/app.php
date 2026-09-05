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
        // CORS solo su api/public/* (vedi config/cors.php). Laravel 12 non
        // registra HandleCors da solo: senza, il browser del visitatore
        // scarta la risposta e il form di contatto non parte da nessun sito,
        // perche' il sito statico e l'API stanno su domini diversi.
        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);

        // Un ospite sulle rotte protette dalla guardia `web` finisce al
        // login del pannello dei contenuti. Laravel per difetto cerca una
        // rotta chiamata `login`, che qui non esiste: i due pannelli
        // Filament registrano le proprie con un nome col prefisso, e senza
        // questo un accesso non autenticato dava "Route [login] not defined"
        // — un errore 500 al posto di un rimando alla pagina d'accesso.
        $middleware->redirectGuestsTo(fn () => route('filament.admin.auth.login'));

        $middleware->alias([
            // Risolve il sito dal dominio nella URL: per gli endpoint
            // pubblici chiamati dal browser del visitatore.
            'site.parametro' => \App\Http\Middleware\RisolviSitoDaParametro::class,
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
