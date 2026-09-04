<?php

namespace App\Models\Concerns;

use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use SensitiveParameter;

/**
 * Implementa i contratti MFA di Filament per un modello utente.
 *
 * Sta in un trait perche' serve identico su User (redattori) e su AdminUser
 * (control plane): duplicarlo avrebbe significato che una correzione a uno
 * dei due non arriva all'altro, ed e' codice di sicurezza.
 *
 * Il segreto e i codici di recupero sono cifrati a riposo: chi legge il
 * database non deve poter rigenerare i codici TOTP di nessuno.
 *
 * @see HasAppAuthentication
 * @see HasAppAuthenticationRecovery
 */
trait HasAppMfa
{
    public function getAppAuthenticationSecret(): ?string
    {
        return $this->app_authentication_secret;
    }

    public function saveAppAuthenticationSecret(#[SensitiveParameter] ?string $secret): void
    {
        $this->app_authentication_secret = $secret;
        $this->save();
    }

    /** Nome mostrato dentro l'app authenticator, per distinguere gli account. */
    public function getAppAuthenticationHolderName(): string
    {
        return $this->email;
    }

    /** @return ?array<string> */
    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        return $this->app_authentication_recovery_codes;
    }

    /** @param ?array<string> $codes */
    public function saveAppAuthenticationRecoveryCodes(#[SensitiveParameter] ?array $codes): void
    {
        $this->app_authentication_recovery_codes = $codes;
        $this->save();
    }

    public function haMfaAttiva(): bool
    {
        return filled($this->app_authentication_secret);
    }
}
