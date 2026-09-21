<?php

namespace App\Filament\Resources\Prodotti\Tables;

use App\Support\Euro;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class ProdottiTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nome')->label('Nome')->searchable()->sortable()->weight('medium'),

                TextColumn::make('prezzo')
                    ->label('Prezzo')
                    ->formatStateUsing(fn (int $state): string => '€ ' . Euro::daCentesimi($state))
                    ->sortable(),

                TextColumn::make('scorte')
                    ->label('Magazzino')
                    ->sortable()
                    ->badge()
                    ->color(fn (int $state): string => $state === 0 ? 'danger' : ($state < 5 ? 'warning' : 'gray'))
                    ->formatStateUsing(fn (int $state): string => $state === 0 ? 'esaurito' : (string) $state),

                TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'published' ? 'Pubblicato' : 'Bozza')
                    ->color(fn (string $state): string => $state === 'published' ? 'success' : 'gray'),

                TextColumn::make('updated_at')->label('Modificato')->since()->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('nome')
            ->filters([
                SelectFilter::make('status')->label('Stato')->options([
                    'draft' => 'Bozza',
                    'published' => 'Pubblicato',
                ]),
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
