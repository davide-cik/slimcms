<?php

namespace App\Filament\Resources\Messaggi\Tables;

use App\Models\Messaggio;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MessaggiTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Ricevuto')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('nome')
                    ->label('Da')
                    ->searchable()
                    // In grassetto quelli non ancora letti: e' la sola
                    // informazione che serve a colpo d'occhio.
                    ->weight(fn (Messaggio $record) => $record->letto_il === null ? 'bold' : null),

                TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->copyable()
                    ->copyMessage('Indirizzo copiato'),

                TextColumn::make('messaggio')
                    ->label('Messaggio')
                    ->limit(80)
                    ->wrap()
                    ->searchable(),

                TextColumn::make('modulo.nome')
                    ->label('Modulo')
                    ->placeholder('—')
                    ->badge(),

                TextColumn::make('pagina')
                    ->label('Dalla pagina')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('letto_il')
                    ->label('Letto')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('mai')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('modulo_id')
                    ->label('Modulo')
                    ->relationship('modulo', 'nome'),

                Filter::make('da_leggere')
                    ->label('Solo da leggere')
                    ->query(fn (Builder $query) => $query->daLeggere())
                    ->default(),
            ])
            ->recordActions([
                Action::make('leggi')
                    ->label('Leggi')
                    ->icon('heroicon-o-envelope-open')
                    ->modalHeading(fn (Messaggio $record) => 'Messaggio di ' . $record->nome)
                    ->modalDescription(fn (Messaggio $record) => $record->email
                        . ' — ' . $record->created_at?->format('d/m/Y H:i'))
                    ->modalContent(fn (Messaggio $record) => view('filament.messaggio', ['messaggio' => $record]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Chiudi')
                    // Aprire il messaggio E' leggerlo: chiedere anche un
                    // clic su "segna come letto" e' un passaggio che nessuno
                    // fa, e il contatore resterebbe rosso per sempre.
                    ->mountUsing(fn (Messaggio $record) => $record->segnaLetto()),

                Action::make('rispondi')
                    ->label('Rispondi')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->url(fn (Messaggio $record) => 'mailto:' . $record->email
                        . '?subject=' . rawurlencode('Re: il tuo messaggio dal sito'))
                    ->openUrlInNewTab(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Nessun messaggio')
            ->emptyStateDescription('Qui arrivano i messaggi scritti dal modulo di contatto del sito.');
    }
}
