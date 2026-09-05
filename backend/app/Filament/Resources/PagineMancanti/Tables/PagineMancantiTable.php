<?php

namespace App\Filament\Resources\PagineMancanti\Tables;

use App\Models\Redirect;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class PagineMancantiTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('percorso')->label('Indirizzo')->searchable()->weight('medium')->wrap(),
                TextColumn::make('colpi')->label('Visite')->badge()->sortable(),
                TextColumn::make('colpi_con_referrer')
                    ->label('Da un collegamento')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray')
                    ->sortable()
                    ->tooltip('Quante volte ci si e arrivati da un link. Se e zero, quasi sempre e uno scanner.'),
                TextColumn::make('ultimo_referrer')->label('Ultimo collegamento')->limit(45)->color('gray')->toggleable(),
                TextColumn::make('ultimo_il')->label('Ultima volta')->since()->sortable(),
            ])
            ->defaultSort('colpi_con_referrer', 'desc')
            ->filters([
                // Il filtro predefinito, non uno fra tanti: senza, l'elenco e'
                // quasi tutto rumore di scanner e diventa un allarme che si
                // impara a ignorare.
                Filter::make('da_guardare')
                    ->label('Solo quelli con un collegamento rotto')
                    ->default()
                    ->query(fn (Builder $query): Builder => $query->where('colpi_con_referrer', '>', 0)),
                TernaryFilter::make('ignorata')->label('Ignorate')->default(false),
            ])
            ->recordActions([
                // L'azione che rende utile questa pagina: da un 404 a un
                // reindirizzamento, senza ricopiare l'indirizzo a mano.
                Action::make('reindirizza')
                    ->label('Reindirizza')
                    ->icon('heroicon-o-arrow-uturn-right')
                    ->schema([
                        TextInput::make('a')
                            ->label('Dove deve portare')
                            ->required()
                            ->maxLength(1000)
                            ->rules(['regex:/^(\/[^\s]*|https?:\/\/[^\s]+)$/'])
                            ->validationMessages(['regex' => 'Deve iniziare con / oppure con https://, senza spazi.'])
                            ->helperText('Il reindirizzamento entra in vigore alla prossima pubblicazione del sito.'),
                        Radio::make('codice')
                            ->label('Tipo')
                            ->options(Redirect::CODICI)
                            ->default(301)
                            ->required(),
                    ])
                    ->action(function (array $data, $record): void {
                        Redirect::updateOrCreate(
                            ['da' => Redirect::normalizza($record->percorso)],
                            [
                                'a' => $data['a'],
                                'codice' => $data['codice'],
                                'attivo' => true,
                                'nota' => "Creato da un 404 ({$record->colpi} visite).",
                            ]
                        );

                        // Sistemato: sparisce dall'elenco, ma la riga resta,
                        // cosi' se il redirect venisse tolto si rivede la
                        // storia invece di ripartire da zero.
                        $record->update(['ignorata' => true]);

                        Notification::make()
                            ->title('Reindirizzamento creato')
                            ->body('Sara attivo alla prossima pubblicazione del sito.')
                            ->success()
                            ->send();
                    }),

                Action::make('ignora')
                    ->label('Ignora')
                    ->icon('heroicon-o-eye-slash')
                    ->color('gray')
                    ->visible(fn ($record): bool => ! $record->ignorata)
                    ->action(fn ($record) => $record->update(['ignorata' => true])),

                // "Ignora" tiene la riga e smette di segnalarla; "Elimina" la
                // toglie del tutto. Servono tutte e due: un collegamento rotto
                // che si e' deciso di lasciare com'e' va ricordato, ma un
                // indirizzo che uno scanner ha provato una volta sola e' solo
                // rumore, e un elenco pieno di rumore diventa un elenco che
                // non si guarda piu'. Se qualcuno ci finisce di nuovo, la riga
                // torna: il conteggio riparte da zero, che e' l'informazione
                // giusta.
                DeleteAction::make()
                    ->label('Elimina')
                    ->modalHeading('Eliminare questo indirizzo dall\'elenco?')
                    ->modalDescription('Se qualcuno ci finisce di nuovo, ricompare come nuovo.'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->label('Elimina'),

                    BulkAction::make('ignora')
                        ->label('Ignora')
                        ->icon('heroicon-o-eye-slash')
                        ->deselectRecordsAfterCompletion()
                        // fetchSelectedRecords, non una update di massa: gli
                        // eventi del modello devono scattare (CLAUDE.md 2-bis).
                        ->fetchSelectedRecords()
                        ->action(fn (Collection $records) => $records->each->update(['ignorata' => true])),
                ]),
            ])
            ->emptyStateHeading('Nessun collegamento rotto')
            ->emptyStateDescription('Gli indirizzi mancanti compaiono qui entro un\'ora da quando qualcuno ci finisce sopra.');
    }
}
