<?php

namespace App\Filament\Resources\Tags\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TagsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Nome')->searchable()->sortable()->weight('medium'),
                TextColumn::make('slug')->label('Slug')->color('gray'),
                // Un tag su zero articoli non serve a niente e riempie le
                // tendine: mostrarlo qui e' il modo di accorgersene.
                TextColumn::make('posts_count')->label('Articoli')->counts('posts')->badge()->sortable(),
            ])
            ->defaultSort('name')
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                // Niente ForceDelete: vedi CLAUDE.md, regola 2-bis.
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }
}
