<?php

namespace App\Filament\Resources\Categories\Schemas;

use App\Support\Slug;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class CategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Nome')
                ->required()
                ->maxLength(120)
                ->live(onBlur: true)
                ->afterStateUpdated(fn (?string $state, callable $set) => $set('slug', Slug::da($state))),

            TextInput::make('slug')
                ->label('Slug')
                ->required()
                ->maxLength(120)
                ->unique(ignoreRecord: true, modifyRuleUsing: Slug::regolaUnica(...)),

            Textarea::make('description')
                ->label('Descrizione')
                ->rows(2)
                ->helperText('Compare nella pagina della categoria e nei dati strutturati.'),
        ])->columns(1);
    }
}
