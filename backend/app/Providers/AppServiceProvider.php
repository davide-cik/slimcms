<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->silenceDeprecationsOnConsole();
    }

    /**
     * Toglie E_DEPRECATED da error_reporting.
     *
     * Perche' serve: PsySH (php artisan tinker) decide se stampare un errore
     * con `$errno & error_reporting()` in Shell.php, IGNORANDO la sua stessa
     * impostazione errorLoggingLevel. Laravel a sua volta imposta
     * error_reporting(-1) in fase di bootstrap. Risultato: ogni E_DEPRECATED
     * finisce a schermo, e stancl/tenancy 3.10 ne emette uno a ogni singola
     * query su un modello scoped per tenant (accede alla proprieta' statica
     * $tenantIdColumn direttamente sul trait, deprecato da PHP 8.2).
     *
     * Cosa resta visibile, verificato: nei comandi artisan e nelle richieste
     * web le deprecation continuano a essere registrate in
     * storage/logs/deprecations.log, perche' Laravel le gestisce in
     * handleError() PRIMA di consultare error_reporting().
     *
     * Eccezione: dentro tinker non vengono registrate, perche' PsySH sostituisce
     * l'error handler di Laravel con il proprio. Non e' una regressione, in
     * tinker non venivano registrate nemmeno prima: venivano solo stampate.
     *
     * Da rimuovere quando stancl sistemera' la cosa a monte.
     */
    private function silenceDeprecationsOnConsole(): void
    {
        error_reporting(error_reporting() & ~E_DEPRECATED);
    }
}
