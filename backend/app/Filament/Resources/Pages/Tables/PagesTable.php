<?php

namespace App\Filament\Resources\Pages\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class PagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                // Nessuna colonna "sito": tutte le righe visibili appartengono
                // gia' al sito corrente, ripeterlo sarebbe rumore.
                TextColumn::make('title')
                    ->label('Titolo')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                TextColumn::make('slug')
                    ->label('Indirizzo')
                    ->searchable()
                    ->color('gray')
                    ->prefix('/'),

                TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'published' => 'Pubblicata',
                        'scheduled' => 'Programmata',
                        default => 'Bozza',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'published' => 'success',
                        'scheduled' => 'warning',
                        default => 'gray',
                    }),

                // Segnala a colpo d'occhio se i campi che ci differenziano
                // sono stati compilati, senza dover aprire la pagina.
                TextColumn::make('seo')
                    ->label('SEO / GEO / AEO')
                    ->state(fn ($record): string => self::riepilogoSeo($record))
                    ->badge()
                    ->color(fn ($record): string => self::punteggioSeo($record) === 3 ? 'success' : 'warning'),

                TextColumn::make('publish_at')
                    ->label('Pubblicazione')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('updated_at')
                    ->label('Ultima modifica')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Stato')
                    ->options([
                        'draft' => 'Bozza',
                        'published' => 'Pubblicata',
                        'scheduled' => 'Programmata',
                    ]),
                TrashedFilter::make()->label('Cestino'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                    // ForceDeleteBulkAction RIMOSSA di proposito.
                    //
                    // Uno dei suoi due percorsi esegue
                    // getSelectedRecordsQuery()->forceDelete(), cioe' la forma
                    // che bypassa i global scope (vedi CLAUDE.md, regola 2-bis).
                    // Non abbiamo verificato se un whereIn sugli id limiti
                    // comunque la query, ma il rapporto rischio/beneficio non
                    // giustifica il dubbio: in un CMS la cancellazione
                    // definitiva di massa non ha uso editoriale, mentre il
                    // danno potenziale e' il contenuto di un altro cliente.
                    //
                    // Per svuotare davvero il cestino: iterare e chiamare
                    // forceDelete() sulle singole istanze, che e' sicuro.
                ]),
            ]);
    }

    private static function punteggioSeo($record): int
    {
        $seo = $record->seo ?? [];
        $punti = 0;

        if (filled($seo['meta_title'] ?? null) && filled($seo['meta_description'] ?? null)) {
            $punti++;
        }

        if (filled($seo['structured_summary'] ?? null)) {
            $punti++;
        }

        if (filled($seo['key_facts'] ?? null)) {
            $punti++;
        }

        return $punti;
    }

    private static function riepilogoSeo($record): string
    {
        return self::punteggioSeo($record) . '/3';
    }
}
