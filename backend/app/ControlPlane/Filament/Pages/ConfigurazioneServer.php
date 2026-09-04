<?php

namespace App\ControlPlane\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Process;
use UnitEnum;

/**
 * Istruzioni per abilitare il provisioning automatico dei certificati.
 *
 * Sta nel control plane e NON sul sito pubblico di proposito: dichiarare in
 * chiaro quali comandi un utente puo' eseguire come root, con nome utente e
 * percorsi, e' materiale utile a chi attacca. Qui e' dietro autenticazione e
 * MFA obbligatoria.
 */
class ConfigurazioneServer extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $title = 'Configurazione server';

    protected static ?string $navigationLabel = 'Configurazione server';

    protected static string|UnitEnum|null $navigationGroup = 'Piattaforma';

    protected static ?int $navigationSort = 10;

    protected string $view = 'control-plane.pages.configurazione-server';

    /** Solo i super-admin: e' configurazione di piattaforma, non assistenza. */
    public static function canAccess(): bool
    {
        return (bool) auth('manage')->user()?->isSuperAdmin();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return [
            'attiva' => $this->regolaAttiva(),
            'percorsoFile' => base_path('../scripts/sudoers/slimcms-hestia'),
            'contenuto' => $this->contenutoRegola(),
            'utente' => config('slimcms.utente_hosting'),
        ];
    }

    /**
     * La regola e' gia' installata? Si verifica provando davvero, non
     * leggendo /etc/sudoers.d, che non e' accessibile senza privilegi.
     */
    private function regolaAttiva(): bool
    {
        return Process::timeout(10)
            ->run('sudo -n -l /usr/local/hestia/bin/v-add-letsencrypt-domain 2>/dev/null')
            ->successful();
    }

    private function contenutoRegola(): string
    {
        $file = base_path('../scripts/sudoers/slimcms-hestia');

        return is_readable($file)
            ? file_get_contents($file)
            : 'File non trovato: scripts/sudoers/slimcms-hestia';
    }
}
