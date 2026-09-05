<?php

namespace App\Listeners;

use App\Http\Controllers\ImpersonazioneController;
use App\Models\Accesso;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Http\Request;

/**
 * Scrive il registro degli accessi ascoltando gli eventi di autenticazione.
 *
 * Sugli eventi e non dentro le pagine di login dei pannelli: gli eventi li
 * emette il framework da **qualunque** percorso porti a un'autenticazione —
 * il form di Filament, l'impersonazione dal control plane, un futuro accesso
 * via API. Un aggancio nella pagina di login coprirebbe solo la pagina di
 * login, e il buco non si vedrebbe finche' non serve.
 *
 * Un errore qui non deve mai impedire a qualcuno di entrare o uscire: se il
 * registro non si scrive, si perde una riga di storico, non l'accesso.
 */
class RegistraAccessi
{
    public function __construct(private readonly Request $request) {}

    public function riuscito(Login $evento): void
    {
        $this->annota(
            guardia: $evento->guard,
            esito: Accesso::RIUSCITO,
            email: $evento->user->email ?? null,
            nome: $evento->user->name ?? null,
            utenteId: $evento->user->getAuthIdentifier(),
            // Una sessione aperta impersonando non e' un accesso del
            // redattore: senza la distinzione, una modifica dell'assistenza
            // sembrerebbe fatta dal cliente.
            impersonato: $this->impersonando(),
        );
    }

    public function fallito(Failed $evento): void
    {
        $this->annota(
            guardia: $evento->guard,
            esito: Accesso::FALLITO,
            // L'utente puo' non esistere affatto: in quel caso resta solo
            // l'email tentata, che e' l'unica cosa che si sa di chi ha
            // provato.
            email: $evento->credentials['email'] ?? ($evento->user->email ?? null),
            nome: $evento->user->name ?? null,
            utenteId: $evento->user?->getAuthIdentifier(),
        );
    }

    public function uscita(Logout $evento): void
    {
        $this->annota(
            guardia: $evento->guard,
            esito: Accesso::USCITA,
            email: $evento->user->email ?? null,
            nome: $evento->user->name ?? null,
            utenteId: $evento->user?->getAuthIdentifier(),
        );
    }

    public function bloccato(Lockout $evento): void
    {
        $this->annota(
            guardia: 'web',
            esito: Accesso::BLOCCATO,
            email: $evento->request->input('email'),
        );
    }

    /**
     * Se questa sessione e' stata aperta impersonando dal control plane.
     *
     * Dalla sessione e non dalla richiesta iniettata: il listener puo' girare
     * dove una richiesta HTTP non c'e' (console, code), e li' chiedere una
     * sessione alla richiesta significa chiederla a un oggetto che non ne ha.
     */
    private function impersonando(): bool
    {
        try {
            return app()->bound('session')
                && session()->isStarted()
                && session()->has(ImpersonazioneController::CHIAVE);
        } catch (\Throwable) {
            return false;
        }
    }

    private function annota(
        string $guardia,
        string $esito,
        ?string $email = null,
        ?string $nome = null,
        int|string|null $utenteId = null,
        bool $impersonato = false,
    ): void {
        try {
            Accesso::create([
                'guardia' => mb_substr($guardia, 0, 12),
                'utente_id' => is_numeric($utenteId) ? (int) $utenteId : null,
                'email' => $email ? mb_substr($email, 0, 180) : null,
                'nome' => $nome ? mb_substr($nome, 0, 120) : null,
                'esito' => $esito,
                'ip' => $this->request->ip(),
                'user_agent' => mb_substr((string) $this->request->userAgent(), 0, 300),
                'impersonato' => $impersonato,
            ]);
        } catch (\Throwable $e) {
            // Il registro non deve mai impedire un accesso o un'uscita.
            logger()->warning('Registro accessi non scritto', ['errore' => $e->getMessage()]);
        }
    }
}
