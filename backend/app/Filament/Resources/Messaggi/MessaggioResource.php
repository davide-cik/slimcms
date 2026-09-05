<?php

namespace App\Filament\Resources\Messaggi;

use App\Filament\Resources\Messaggi\Pages\ListMessaggi;
use App\Filament\Resources\Messaggi\Tables\MessaggiTable;
use App\Models\Messaggio;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * I messaggi arrivati dal form di contatto.
 *
 * Sola lettura: non si crea un messaggio a mano e non si modifica quello che
 * ha scritto una persona. Si legge, si risponde per email, si archivia.
 */
class MessaggioResource extends Resource
{
    protected static ?string $model = Messaggio::class;

    protected static ?string $modelLabel = 'messaggio';

    protected static ?string $pluralModelLabel = 'messaggi';

    protected static ?string $navigationLabel = 'Messaggi';

    protected static ?string $slug = 'messaggi';

    protected static ?string $recordTitleAttribute = 'nome';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    public static function table(Table $table): Table
    {
        return MessaggiTable::configure($table);
    }

    /** Il numero di quelli non letti, sulla voce di menu. */
    public static function getNavigationBadge(): ?string
    {
        $daLeggere = Messaggio::query()->daLeggere()->count();

        return $daLeggere > 0 ? (string) $daLeggere : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMessaggi::route('/'),
        ];
    }
}
