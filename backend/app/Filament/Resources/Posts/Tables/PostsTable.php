<?php

namespace App\Filament\Resources\Posts\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class PostsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->label('Titolo')->searchable()->sortable()->weight('medium'),

                TextColumn::make('author.name')->label('Autore')->visibleFrom('lg')->placeholder('—')->color('gray'),

                TextColumn::make('categories.name')
                    ->label('Categorie')
                    ->visibleFrom('lg')
                    ->badge()
                    ->separator(',')
                    ->placeholder('—'),

                TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'published' => 'Pubblicato',
                        'scheduled' => 'Programmato',
                        default => 'Bozza',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'published' => 'success',
                        'scheduled' => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('publish_at')
                    ->label('Pubblicazione')
                    ->visibleFrom('md')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable()
                    // Un articolo "pubblicato" con data futura non e' ancora
                    // visibile: va detto, altrimenti sembra un bug del sito.
                    ->description(fn ($record): ?string => $record->publish_at?->isFuture()
                        ? 'non ancora visibile'
                        : null),

                TextColumn::make('updated_at')->label('Modificato')->since()->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('publish_at', 'desc')
            ->filters([
                SelectFilter::make('status')->label('Stato')->options([
                    'draft' => 'Bozza',
                    'published' => 'Pubblicato',
                    'scheduled' => 'Programmato',
                ]),
                SelectFilter::make('categories')->label('Categoria')->relationship('categories', 'name'),
                TrashedFilter::make()->label('Cestino'),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                // Niente ForceDeleteBulkAction: vedi CLAUDE.md, regola 2-bis.
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
