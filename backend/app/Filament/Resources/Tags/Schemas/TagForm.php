<?php

namespace App\Filament\Resources\Tags\Schemas;

use App\Support\Slug;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

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
                ->afterStateUpdated(fn (?string $state, callable $set) => $set('slug', Slug::da($state))),

            TextInput::make('slug')
                ->label('Slug')
                ->required()
                ->maxLength(60)
                ->unique(ignoreRecord: true, modifyRuleUsing: Slug::regolaUnica(...))
                ->helperText('L\'indirizzo della pagina d\'archivio: /tag/<slug>/'),
        ])->columns(2);
    }
}
