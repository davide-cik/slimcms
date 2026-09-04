<?php

namespace App\Filament\Resources\MediaLibrary;

use App\Models\Media;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Libreria media del sito.
 *
 * Il caricamento avviene dai form di pagine e articoli o dall'azione qui
 * sotto; questa risorsa serve a rivedere, rinominare e cancellare cio' che
 * e' gia' stato caricato.
 */
class MediaResource extends Resource
{
    protected static ?string $model = Media::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static ?string $modelLabel = 'file';

    protected static ?string $pluralModelLabel = 'libreria media';

    protected static ?string $navigationLabel = 'Media';

    protected static ?string $recordTitleAttribute = 'name';

    /** Media ha site_id tramite BelongsToSite, quindi la relazione e' 'site'. */
    protected static ?string $tenantOwnershipRelationshipName = 'site';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Nome')
                ->required()
                ->maxLength(180)
                ->helperText('Come lo trovi cercando nella libreria. Non cambia il nome del file.'),

            TextInput::make('custom_properties.alt')
                ->label('Testo alternativo')
                ->maxLength(300)
                ->helperText('Descrive l\'immagine a chi non la vede, e ai motori di ricerca. Scrivilo sempre.'),

            TextInput::make('custom_properties.didascalia')
                ->label('Didascalia')
                ->maxLength(300),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('anteprima')
                    ->label('')
                    ->getStateUsing(fn (Media $record): ?string => $record->hasGeneratedConversion('anteprima')
                        ? $record->getUrl('anteprima')
                        : null)
                    ->size(56)
                    ->extraImgAttributes(['loading' => 'lazy']),

                TextColumn::make('name')->label('Nome')->searchable()->sortable()->weight('medium')
                    ->description(fn (Media $record): string => $record->file_name),

                TextColumn::make('alt')
                    ->label('Testo alternativo')
                    // Un'immagine senza alt e' un problema di accessibilita' e
                    // di SEO: va segnalata, non lasciata vuota in silenzio.
                    ->state(fn (Media $record): string => filled($record->getCustomProperty('alt'))
                        ? 'presente'
                        : 'mancante')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'presente' ? 'success' : 'warning'),

                TextColumn::make('mime_type')->label('Tipo')->badge()->color('gray')
                    ->formatStateUsing(fn (string $state): string => str_contains($state, '/')
                        ? explode('/', $state)[1]
                        : $state),

                TextColumn::make('size')
                    ->label('Dimensione')
                    ->formatStateUsing(fn (int $state): string => $state > 1048576
                        ? round($state / 1048576, 1) . ' MB'
                        : round($state / 1024) . ' KB')
                    ->sortable(),

                TextColumn::make('created_at')->label('Caricato')->since()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('mime_type')
                    ->label('Tipo')
                    ->options([
                        'image/jpeg' => 'JPEG',
                        'image/png' => 'PNG',
                        'image/webp' => 'WebP',
                        'image/svg+xml' => 'SVG',
                        'application/pdf' => 'PDF',
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
                // La cancellazione di un media porta via anche il file dal
                // disco: Spatie lo fa nell'evento deleted del modello.
                DeleteAction::make()
                    ->modalDescription('Il file viene rimosso anche dallo spazio di archiviazione. Le pagine che lo usano mostreranno un\'immagine mancante.'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ])
            ->emptyStateHeading('Nessun file caricato')
            ->emptyStateDescription('I file caricati dai form di pagine e articoli compaiono qui.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMedia::route('/'),
            'edit' => Pages\EditMedia::route('/{record}/edit'),
        ];
    }

    /** Il caricamento avviene dai form dei contenuti, non da qui. */
    public static function canCreate(): bool
    {
        return false;
    }
}
