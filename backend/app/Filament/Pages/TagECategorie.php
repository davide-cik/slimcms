<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\Tags\TagResource;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Tag e categorie in una pagina sola, due riquadri.
 *
 * Erano due voci di menu per due tabelle da dieci righe, e sono cose che si
 * decidono guardandole insieme: un tag nuovo ha senso solo rispetto alle
 * categorie che ci sono gia'.
 *
 * Le due risorse restano — con le loro rotte, i loro form e le loro policy —
 * ma spariscono dalla barra laterale: qui si vedono e si modificano in
 * finestra, senza cambiare pagina.
 */
class TagECategorie extends Page
{
    protected string $view = 'filament.pages.tag-e-categorie';

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $navigationLabel = 'Tag e categorie';

    protected static ?string $title = 'Tag e categorie';

    protected static ?string $slug = 'tag-e-categorie';

    protected static ?int $navigationSort = 40;

    /**
     * Le pagine non hanno un modello e quindi non hanno una policy: il
     * controllo lo dichiarano loro. Qui si riusa quello delle due risorse
     * invece di riscriverlo — se domani cambia la soglia di `TagPolicy`,
     * cambia anche qui senza che nessuno se ne debba ricordare.
     */
    public static function canAccess(): bool
    {
        return TagResource::canViewAny() || CategoryResource::canViewAny();
    }
}
