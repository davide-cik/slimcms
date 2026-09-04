<?php

namespace App\Filament\Resources\Categories\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Nome')->searchable()->sortable()->weight('medium'),
                TextColumn::make('slug')->label('Slug')->color('gray'),
                TextColumn::make('posts_count')->label('Articoli')->counts('posts')->badge(),
            ])
            ->defaultSort('name')
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                // Niente ForceDelete: vedi CLAUDE.md, regola 2-bis.
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }
}
