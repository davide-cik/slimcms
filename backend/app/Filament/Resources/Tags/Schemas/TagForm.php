<?php

namespace App\Filament\Resources\Tags\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class TagForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Nome')
                ->required()
                ->maxLength(60)
                ->live(onBlur: true)
                ->afterStateUpdated(fn (?string $state, callable $set) => $set('slug', Str::slug((string) $state))),

            TextInput::make('slug')
                ->label('Slug')
                ->required()
                ->maxLength(60)
                ->helperText('L\'indirizzo della pagina d\'archivio: /tag/<slug>/'),
        ])->columns(2);
    }
}
