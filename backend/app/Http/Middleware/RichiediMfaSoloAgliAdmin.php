<?php

namespace App\Http\Middleware;

use Closure;
use Filament\Auth\MultiFactor\Http\Middleware\EnsureMultiFactorAuthenticationIsEnabled;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Obbliga alla MFA solo chi ha ruolo admin su almeno un sito.
 *
 * PERCHE' UN MIDDLEWARE E NON UNA CLOSURE:
 * Filament accetta multiFactorAuthentication(..., isRequired: Closure), ma
 * quella closure viene valutata al BOOT, quando registra rotte e middleware
 * (HasComponents.php:594 e Pages/Concerns/HasRoutes.php:123), non a ogni
 * richiesta. A quel momento non c'e' nessun utente autenticato: una closure
 * per-utente restituirebbe sempre false, la pagina di setup non verrebbe
 * registrata e chi ne ha bisogno finirebbe su una rotta inesistente.
 *
 * Quindi il pannello dichiara isRequired: true (cosi' rotte e middleware
 * esistono) e l'esenzione per i ruoli non amministrativi la applica questo
 * middleware, che gira per richiesta e ha l'utente sotto mano.
 *
 * Gli altri ruoli possono comunque attivare la MFA dal proprio profilo:
 * qui si decide solo chi e' OBBLIGATO.
 */
class RichiediMfaSoloAgliAdmin extends EnsureMultiFactorAuthenticationIsEnabled
{
    public function handle(Request $request, Closure $next): mixed
    {
        $utente = Filament::auth()->user();

        if ($utente === null) {
            return $next($request);
        }

        $eAdmin = $utente->sites()
            ->withoutTenancy()
            ->wherePivot('role', 'admin')
            ->exists();

        if (! $eAdmin) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }
}
