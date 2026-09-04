<?php

namespace App\ControlPlane;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class ControlPlanePanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('manage')
            // Guardia dedicata: la sessione del control plane e' separata da
            // quella dei redattori. Un accesso non puo' attraversare i due
            // piani nemmeno per un errore di configurazione.
            ->authGuard('manage')
            ->login()
            // MFA OBBLIGATORIA per tutti, senza eccezioni: chi entra qui puo'
            // creare, sospendere e cancellare clienti interi. Le specifiche la
            // chiedono per super-admin; qui vale anche per il supporto, perche'
            // la differenza di ruolo non cambia la sensibilita' dell'accesso.
            ->multiFactorAuthentication(
                AppAuthentication::make()->recoverable(),
                isRequired: true,
            )
            // Su un dominio dedicato in produzione (manage.slimcms.it), sul
            // percorso /manage in sviluppo dove il dominio non esiste.
            // Quando il dominio c'e', il path resta vuoto: e' il dominio
            // stesso a identificare il pannello.
            ->when(
                filled(config('slimcms.dominio_manage')),
                fn ($panel) => $panel->domain(config('slimcms.dominio_manage'))->path(''),
                fn ($panel) => $panel->path('manage'),
            )
            ->colors([
                // Rosso ruggine, deliberatamente DIVERSO dal verde del pannello
                // dei siti: chi amministra deve capire a colpo d'occhio se sta
                // toccando la piattaforma o il contenuto di un cliente.
                'primary' => Color::hex('#9c3d2e'),
            ])
            ->brandName('SlimCMS · Gestione')
            // Rossa come il pannello: la scheda del browser dice a colpo d'occhio
            // se stai amministrando la piattaforma o i contenuti di un cliente.
            ->favicon(asset('img/favicon-manage.svg'))
            ->discoverResources(in: app_path('ControlPlane/Filament/Resources'), for: 'App\ControlPlane\Filament\Resources')
            ->discoverPages(in: app_path('ControlPlane/Filament/Pages'), for: 'App\ControlPlane\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('ControlPlane/Filament/Widgets'), for: 'App\ControlPlane\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
