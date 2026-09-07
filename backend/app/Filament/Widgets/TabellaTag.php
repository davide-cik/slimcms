<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Tags\Schemas\TagForm;
use App\Filament\Resources\Tags\Tables\TagsTable;
use App\Models\Tag;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * I tag, come riquadro dentro «Tag e categorie».
 *
 * Sono due elenchi corti che si guardano insieme — un tag e una categoria si
 * decidono uno guardando l'altro — e due voci di menu per due tabelle da
 * dieci righe erano due voci di troppo.
 *
 * Colonne e azioni di massa vengono da `TagsTable`, la stessa che usa la
 * risorsa: qui si aggiungono solo la query (nel widget non la mette nessuno)
 * e la modifica in finestra, che su un elenco di etichette e' la differenza
 * fra correggere un refuso e cambiare pagina due volte.
 */
class TabellaTag extends TableWidget
{
    protected int | string | array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return TagsTable::configure($table)
            ->query(Tag::query())
            ->heading('Tag')
            ->description('Le parole chiave degli articoli. Un tag su zero articoli riempie le tendine e basta.')
            ->headerActions([
                CreateAction::make()
                    ->label('Nuovo tag')
                    ->model(Tag::class)
                    ->schema(fn (Schema $schema): Schema => TagForm::configure($schema)),
            ])
            ->recordActions([
                EditAction::make()
                    ->schema(fn (Schema $schema): Schema => TagForm::configure($schema)),
            ])
            ->emptyStateHeading('Nessun tag')
            ->emptyStateDescription('I tag si creano anche dal form di un articolo.');
    }
}
