<?php

namespace App\Http\Middleware;

use Closure;
use Filament\Auth\MultiFactor\Http\Middleware\EnsureMultiFactorAuthenticationIsEnabled;
use App\Http\Controllers\ImpersonazioneController;
use App\Models\Impersonazione;
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

        // Sessione aperta impersonando dal control plane: il secondo fattore
        // e' GIA' stato dimostrato li', dove l'MFA e' obbligatoria per tutti.
        // Chiederlo una seconda volta non aggiunge sicurezza — l'identita' e'
        // la stessa persona, gia' verificata pochi secondi prima — e obbliga
        // a iscrivere un secondo dispositivo per lo stesso essere umano.
        if ($this->apertaDaAdminConMfa($request)) {
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

    /**
     * La sessione nasce da un'impersonazione aperta da un amministratore che
     * aveva l'MFA attiva?
     *
     * Si verifica sul RECORD, non solo sulla presenza della chiave di
     * sessione: cosi' l'esenzione poggia su un fatto registrato e verificabile
     * a posteriori, non su un valore che sta solo nella sessione.
     */
    private function apertaDaAdminConMfa(Request $request): bool
    {
        $id = $request->session()->get(ImpersonazioneController::CHIAVE);

        if ($id === null) {
            return false;
        }

        return (bool) Impersonazione::with('adminUser')
            ->whereKey($id)
            ->first()
            ?->adminUser
            ?->haMfaAttiva();
    }
}
