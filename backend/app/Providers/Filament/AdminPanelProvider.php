<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use App\Http\Middleware\RichiediMfaSoloAgliAdmin;
use App\Http\Middleware\SetCurrentSiteFromFilamentTenant;
use App\Models\Site;
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

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            // Chiunque puo' attivarla; e' OBBLIGATORIA per chi ha ruolo admin
            // su almeno un sito (specifiche, punto 6 dell'MVP), perche' un
            // admin puo' aggiungere e rimuovere altri redattori.
            // isRequired: true serve a far REGISTRARE rotte e middleware, che
            // Filament crea solo se la MFA risulta obbligatoria al boot.
            // L'esenzione vera per i ruoli non amministrativi la applica
            // RichiediMfaSoloAgliAdmin, che gira per richiesta e vede l'utente.
            ->multiFactorAuthentication(
                AppAuthentication::make()->recoverable(),
                isRequired: true,
            )
            ->multiFactorAuthenticationRequiredMiddlewareName(RichiediMfaSoloAgliAdmin::class)
            ->colors([
                // verde pino: lo stesso colore segnale della vetrina slimcms.it
                'primary' => Color::hex('#0f6b4a'),
            ])
            // Multi-tenancy nativa di Filament. Il "tenant" del data plane e' il
            // Site, NON il Tenant: il selettore in /admin serve a passare da un
            // mini sito all'altro, che e' cio' che descrive la sezione 8 delle
            // specifiche. Con un solo sito Filament salta il selettore da solo.
            ->favicon(asset('img/favicon-admin.svg'))
            ->tenant(Site::class, slugAttribute: 'domain')
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
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
            ->tenantMiddleware([
                // Allinea il binding 'currentSite' al tenant scelto in Filament,
                // cosi' i global scope di BelongsToSite filtrano sul sito giusto
                // anche dentro il pannello. Va in tenantMiddleware, non in
                // middleware: gira DOPO che Filament ha risolto il tenant.
                SetCurrentSiteFromFilamentTenant::class,
            ], isPersistent: true)
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
