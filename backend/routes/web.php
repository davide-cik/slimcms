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
