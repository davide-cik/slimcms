<?php

namespace App\Filament\Resources\Prodotti;

use App\Filament\Resources\Prodotti\Pages\CreateProdotto;
use App\Filament\Resources\Prodotti\Pages\EditProdotto;
use App\Filament\Resources\Prodotti\Pages\ListProdotti;
use App\Filament\Resources\Prodotti\Schemas\ProdottoForm;
use App\Filament\Resources\Prodotti\Tables\ProdottiTable;
use App\Models\Prodotto;
use App\Models\Site;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ProdottoResource extends Resource
{
    protected static ?string $model = Prodotto::class;

    protected static ?string $modelLabel = 'prodotto';

    protected static ?string $pluralModelLabel = 'prodotti';

    protected static ?string $navigationLabel = 'Prodotti';

    protected static ?string $recordTitleAttribute = 'nome';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    /**
     * Il negozio lo accende il control plane. Spento, la voce sparisce e la
     * URL risponde 403: nascondere la voce da sola non sarebbe un controllo.
     */
    public static function canAccess(): bool
    {
        $sito = Filament::getTenant();

        return $sito instanceof Site && $sito->negozioAttivo() && parent::canAccess();
    }

    public static function form(Schema $schema): Schema
    {
        return ProdottoForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProdottiTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProdotti::route('/'),
            'create' => CreateProdotto::route('/create'),
            'edit' => EditProdotto::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
