<?php

namespace App\Filament\Resources\Redirects\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class RedirectsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('da')->label('Da')->searchable()->sortable()->weight('medium'),
                TextColumn::make('a')->label('A')->searchable()->color('gray')->limit(60),
                TextColumn::make('codice')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (int $state): string => $state === 301 ? 'Permanente' : 'Temporaneo')
                    ->color(fn (int $state): string => $state === 301 ? 'success' : 'warning'),
                IconColumn::make('attivo')->label('Attivo')->boolean(),
                TextColumn::make('nota')->label('Nota')->color('gray')->limit(40)->toggleable(),
            ])
            ->defaultSort('da')
            ->filters([
                TernaryFilter::make('attivo')->label('Attivo'),
                SelectFilter::make('codice')->label('Tipo')->options([
                    301 => 'Permanente (301)',
                    302 => 'Temporaneo (302)',
                ]),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                // Niente ForceDelete: vedi CLAUDE.md, regola 2-bis.
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }
}
