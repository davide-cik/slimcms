<?php

namespace App\Filament\Resources\Moduli\Tables;

use App\Models\Modulo;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ModuliTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nome')->label('Nome')->searchable()->sortable(),

                TextColumn::make('slug')->label('Sigla')->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('campi')
                    ->label('Campi in piu\'')
                    ->formatStateUsing(fn (Modulo $r): string => (string) count($r->campiNormalizzati()))
                    ->alignEnd(),

                TextColumn::make('messaggi_count')
                    ->label('Messaggi')
                    ->counts('messaggi')
                    ->alignEnd(),

                TextColumn::make('email_destinatario')
                    ->label('Destinatario')
                    ->placeholder('quello del sito')
                    ->formatStateUsing(fn (?string $state, Modulo $r): string => $state ?: (string) $r->destinatario()),

                IconColumn::make('attivo')->label('Attivo')->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->modalHeading('Eliminare il modulo?')
                    // I messaggi restano: sono di chi li ha scritti, non del
                    // modulo che li ha raccolti.
                    ->modalDescription('I messaggi gia\' ricevuti restano in «Messaggi».'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ])
            ->emptyStateHeading('Nessun modulo')
            ->emptyStateDescription('Crea un modulo, poi mettilo in una pagina col blocco «Modulo di contatto».');
    }
}
