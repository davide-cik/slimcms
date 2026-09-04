<?php

namespace App\Filament\Resources\PagineMancanti;

use App\Filament\Resources\PagineMancanti\Pages\ListPagineMancanti;
use App\Filament\Resources\PagineMancanti\Tables\PagineMancantiTable;
use App\Models\PaginaMancante;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PaginaMancanteResource extends Resource
{
    protected static ?string $model = PaginaMancante::class;

    protected static ?string $modelLabel = 'pagina mancante';

    protected static ?string $pluralModelLabel = 'pagine mancanti';

    protected static ?string $navigationLabel = 'Pagine mancanti';

    protected static ?string $recordTitleAttribute = 'percorso';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    public static function table(Table $table): Table
    {
        return PagineMancantiTable::configure($table);
    }

    /** Il numero accanto alla voce di menu: quanti collegamenti rotti ci sono. */
    public static function getNavigationBadge(): ?string
    {
        $n = PaginaMancante::daGuardare()->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function canCreate(): bool
    {
        // Non si creano a mano: le scrive il sito quando qualcuno ci finisce.
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListPagineMancanti::route('/')];
    }
}
