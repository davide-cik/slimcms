<?php

namespace App\Http\Controllers;

use App\Models\Impersonazione;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Consuma un token di impersonazione e apre il pannello dei contenuti.
 *
 * Il super-admin NON riceve l'accesso al data plane: entra come un redattore
 * esistente, per un solo accesso, e la sessione porta il segno di chi c'e'
 * dietro. Senza quel segno una modifica dell'assistenza sarebbe
 * indistinguibile da una del cliente.
 */
class ImpersonazioneController extends Controller
{
    /** Chiave di sessione con l'id dell'amministratore dietro la sessione. */
    public const CHIAVE = 'impersonato_da';

    public function entra(Request $request, string $token)
    {
        $imp = Impersonazione::where('token', $token)->first();

        // Messaggio unico per token inesistente, gia' speso o scaduto: dire
        // quale dei tre e' vero aiuterebbe solo chi prova a indovinarli.
        if ($imp === null || ! $imp->spendibile()) {
            abort(403, 'Accesso non valido o scaduto. Riparti dal pannello di gestione.');
        }

        $imp->forceFill(['usato_il' => now(), 'ip' => $request->ip()])->save();

        Auth::guard('web')->loginUsingId($imp->user_id);
        $request->session()->regenerate();
        $request->session()->put(self::CHIAVE, $imp->id);

        return redirect('/admin/' . $imp->site->domain);
    }

    public function esci(Request $request)
    {
        if ($id = $request->session()->pull(self::CHIAVE)) {
            Impersonazione::whereKey($id)->update(['terminata_il' => now()]);
        }

        Auth::guard('web')->logout();
        $request->session()->regenerate();

        // Torna da dove si e' partiti: il control plane.
        return redirect(config('slimcms.dominio_manage')
            ? 'https://' . config('slimcms.dominio_manage') . '/sites'
            : '/manage/sites');
    }
}
