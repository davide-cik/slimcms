<?php

namespace App\ControlPlane\Filament\Pages;

use App\Services\Rilasci as ServizioRilasci;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Storico dei rilasci.
 *
 * Ogni commit e' un rilascio e vale 0.0.1: la versione si deriva dalla
 * posizione nella storia, quindi non puo' divergere dal codice che gira.
 */
class Rilasci extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRocketLaunch;

    protected static ?string $title = 'Rilasci';

    protected static ?string $navigationLabel = 'Rilasci';

    protected static string|UnitEnum|null $navigationGroup = 'Piattaforma';

    protected static ?int $navigationSort = 20;

    protected string $view = 'control-plane.pages.rilasci';

    protected static ?string $slug = 'rilasci';

    /** In URL cosi' una pagina dell'elenco resta condivisibile. */
    #[Url(as: 'p')]
    public int $pagina = 1;

    /**
     * La versione compare come badge nella spalla laterale: si vede a colpo
     * d'occhio cosa sta girando, e cliccando si apre lo storico.
     */
    public static function getNavigationBadge(): ?string
    {
        return app(ServizioRilasci::class)->versione();
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Versione in esecuzione. Ogni commit vale 0.0.1.';
    }

    public static function canAccess(): bool
    {
        return (bool) auth('manage')->user()?->isSuperAdmin();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $servizio = app(ServizioRilasci::class);
        $totali = $servizio->pagineTotali();
        $pagina = max(1, min($this->pagina, $totali));

        return [
            'rilasci' => $servizio->pagina($pagina),
            'pagina' => $pagina,
            'pagineTotali' => $totali,
            'totale' => $servizio->tutti()->count(),
            'versioneCorrente' => $servizio->versione(),
            'perPagina' => ServizioRilasci::PER_PAGINA,
        ];
    }

    public function vaiA(int $pagina): void
    {
        $this->pagina = $pagina;
    }
}
