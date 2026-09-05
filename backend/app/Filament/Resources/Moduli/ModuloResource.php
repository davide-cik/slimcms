<?php

namespace App\Filament\Resources\Moduli;

use App\Filament\Resources\Moduli\Pages\CreateModulo;
use App\Filament\Resources\Moduli\Pages\EditModulo;
use App\Filament\Resources\Moduli\Pages\ListModuli;
use App\Filament\Resources\Moduli\Schemas\ModuloForm;
use App\Filament\Resources\Moduli\Tables\ModuliTable;
use App\Models\Modulo;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * I moduli del sito: contatti, preventivo, iscrizione, quello che serve.
 *
 * Ognuno ha i propri campi e il proprio destinatario. I messaggi arrivano
 * tutti in «Messaggi», dove si filtrano per modulo.
 */
class ModuloResource extends Resource
{
    protected static ?string $model = Modulo::class;

    protected static ?string $modelLabel = 'modulo';

    protected static ?string $pluralModelLabel = 'moduli';

    protected static ?string $navigationLabel = 'Moduli';

    protected static ?string $slug = 'moduli';

    protected static ?string $recordTitleAttribute = 'nome';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    public static function form(Schema $schema): Schema
    {
        return ModuloForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ModuliTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListModuli::route('/'),
            'create' => CreateModulo::route('/create'),
            'edit' => EditModulo::route('/{record}/edit'),
        ];
    }
}
