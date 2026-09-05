<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\Ruolo;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Nome')
                ->required()
                ->maxLength(255),

            TextInput::make('email')
                ->label('Email')
                ->email()
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(255),

            TextInput::make('password')
                ->label('Password')
                ->password()
                ->revealable()
                ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? Hash::make($state) : null)
                ->dehydrated(fn (?string $state): bool => filled($state))
                ->required(fn (string $operation): bool => $operation === 'create')
                ->helperText(fn (string $operation): string => $operation === 'edit'
                    ? 'Lascia vuoto per non cambiarla.'
                    : 'Almeno 8 caratteri.')
                ->minLength(8),

            // Il ruolo sta sul pivot: la stessa persona puo' essere admin su
            // un sito e author su un altro. Questa tendina agisce sul ruolo
            // relativo al SITO CORRENTE, non su un attributo dell'utente.
            Select::make('ruolo_sul_sito')
                ->label('Ruolo su questo sito')
                // Le etichette stanno sull'enum insieme alla scala dei
                // poteri: sono una promessa, e le policy che la fanno
                // rispettare leggono la stessa fonte. Scritte qui a mano
                // sarebbero libere di divergere, ed e' cosi' che nasce un
                // ruolo che dice una cosa e ne fa un'altra.
                ->options(Ruolo::opzioni())
                ->default('editor')
                ->required()
                ->dehydrated(false)
                ->afterStateHydrated(function (Select $component, $record) {
                    $site = \Filament\Facades\Filament::getTenant();

                    if ($record && $site) {
                        $component->state($record->sites()->withoutTenancy()->whereKey($site)->first()?->pivot->role ?? 'editor');
                    }
                }),
        ])->columns(1);
    }
}
