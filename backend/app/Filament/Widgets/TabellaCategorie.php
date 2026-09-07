<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Categories\Schemas\CategoryForm;
use App\Filament\Resources\Categories\Tables\CategoriesTable;
use App\Models\Category;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Le categorie, come riquadro dentro «Tag e categorie». Vedi TabellaTag. */
class TabellaCategorie extends TableWidget
{
    protected int | string | array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return CategoriesTable::configure($table)
            ->query(Category::query())
            ->heading('Categorie')
            ->description('La sezione editoriale di un articolo. Ognuna ha una pagina di archivio sul sito.')
            ->headerActions([
                CreateAction::make()
                    ->label('Nuova categoria')
                    ->model(Category::class)
                    ->schema(fn (Schema $schema): Schema => CategoryForm::configure($schema)),
            ])
            ->recordActions([
                EditAction::make()
                    ->schema(fn (Schema $schema): Schema => CategoryForm::configure($schema)),
            ])
            ->emptyStateHeading('Nessuna categoria');
    }
}
