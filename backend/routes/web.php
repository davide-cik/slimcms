<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

use App\Http\Controllers\ImpersonazioneController;

/*
 * Impersonazione: un amministratore di piattaforma entra nel pannello dei
 * contenuti come un redattore esistente. Il token e' monouso e scade in un
 * minuto; il controllo su chi puo' emetterlo sta nel control plane.
 */
Route::get('/impersona/{token}', [ImpersonazioneController::class, 'entra'])
    ->middleware('web')
    ->name('impersona.entra');

Route::post('/impersona/esci', [ImpersonazioneController::class, 'esci'])
    ->middleware('web')
    ->name('impersona.esci');

use App\Http\Controllers\AnteprimaOpenGraphController;

/*
 * Anteprima dell'immagine Open Graph nel control plane. Non e' sotto /api
 * perche' l'autorizzazione qui e' la sessione, non un token di build.
 */
Route::get('/anteprima-og/{site}', AnteprimaOpenGraphController::class)
    ->middleware('web')
    ->name('anteprima.og');

/*
 * L'anteprima di una pagina, bozze comprese.
 *
 * Fuori dal pannello Filament di proposito: dev'essere un documento HTML
 * intero con i fogli di stile del sito, senza la cornice dell'amministrazione.
 * L'autorizzazione la fa la policy, dentro il controller.
 */
Route::get('/anteprima/pagina/{pagina}', \App\Http\Controllers\AnteprimaController::class)
    ->whereNumber('pagina')
    ->middleware(['web', 'auth:web'])
    ->name('anteprima.pagina');
