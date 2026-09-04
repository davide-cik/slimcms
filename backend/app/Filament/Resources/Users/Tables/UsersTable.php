<?php

namespace App\Filament\Resources\Users\Tables;

use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Nome')->searchable()->sortable()->weight('medium'),
                TextColumn::make('email')->label('Email')->searchable()->copyable()->color('gray'),

                TextColumn::make('ruolo')
                    ->label('Ruolo su questo sito')
                    ->badge()
                    ->state(function ($record): string {
                        $site = Filament::getTenant();
                        $ruolo = $site
                            ? $record->sites()->withoutTenancy()->whereKey($site)->first()?->pivot->role
                            : null;

                        return match ($ruolo) {
                            'admin' => 'Amministratore',
                            'author' => 'Autore',
                            'viewer' => 'Sola lettura',
                            'editor' => 'Redattore',
                            default => '—',
                        };
                    })
                    ->color(fn (string $state): string => $state === 'Amministratore' ? 'success' : 'gray'),

                TextColumn::make('altri_siti')
                    ->label('Altri siti')
                    ->state(function ($record): string {
                        $site = Filament::getTenant();
                        $n = $record->sites()->withoutTenancy()
                            ->when($site, fn ($q) => $q->whereKeyNot($site))
                            ->count();

                        return $n === 0 ? '—' : (string) $n;
                    })
                    ->color('gray')
                    ->tooltip('Numero di altri siti su cui questa persona lavora'),
            ])
            ->defaultSort('name')
            ->recordActions([EditAction::make()])
            // Nessuna azione di massa: rimuovere redattori in blocco non ha
            // uso pratico e le azioni di delete di Filament agiscono
            // sull'utente, non sul legame col sito.
            ->toolbarActions([]);
    }
}
