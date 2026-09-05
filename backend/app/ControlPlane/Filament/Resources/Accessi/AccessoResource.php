<?php

namespace App\ControlPlane\Filament\Resources\Accessi;

use App\ControlPlane\Filament\Resources\Accessi\Pages\ListAccessi;
use App\Models\Accesso;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Chi entra, chi ci prova e non riesce.
 *
 * Sta nel control plane e non nel pannello di un sito: un tentativo fallito
 * non appartiene a nessun sito — l'email potrebbe non esistere affatto — e la
 * domanda "qualcuno sta provando a entrare che non dovrebbe" e' di
 * piattaforma.
 *
 * Sola lettura: un registro che si puo' correggere non e' un registro.
 */
class AccessoResource extends Resource
{
    protected static ?string $model = Accesso::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFingerPrint;

    protected static ?string $modelLabel = 'accesso';

    protected static ?string $pluralModelLabel = 'accessi';

    protected static ?string $navigationLabel = 'Accessi';

    protected static ?string $slug = 'accessi';

    protected static ?int $navigationSort = 80;

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->poll('60s')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Quando')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable(),

                TextColumn::make('esito')
                    ->label('Esito')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        Accesso::RIUSCITO => 'Entrato',
                        Accesso::FALLITO => 'Fallito',
                        Accesso::USCITA => 'Uscito',
                        Accesso::BLOCCATO => 'Bloccato',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        Accesso::RIUSCITO => 'success',
                        Accesso::FALLITO => 'warning',
                        Accesso::BLOCCATO => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('email')
                    ->label('Chi')
                    ->searchable()
                    ->description(fn (Accesso $r): ?string => $r->nome)
                    ->placeholder('sconosciuto'),

                TextColumn::make('guardia')
                    ->label('Pannello')
                    ->formatStateUsing(fn (string $state, Accesso $r): string => $r->pannello())
                    ->badge()
                    ->color(fn (string $state): string => $state === 'manage' ? 'danger' : 'gray'),

                TextColumn::make('impersonato')
                    ->label('Impersonando')
                    ->formatStateUsing(fn (bool $state): string => $state ? 'sì' : '—')
                    ->toggleable(),

                TextColumn::make('ip')
                    ->label('Indirizzo')
                    ->searchable()
                    ->copyable()
                    ->copyMessage('Indirizzo copiato'),

                TextColumn::make('user_agent')
                    ->label('Programma')
                    ->limit(40)
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                // Predefinito: e' la domanda per cui questo elenco esiste.
                Filter::make('solo_falliti')
                    ->label('Solo tentativi falliti')
                    ->query(fn (Builder $query) => $query->falliti()),

                SelectFilter::make('guardia')
                    ->label('Pannello')
                    ->options(['web' => 'Contenuti', 'manage' => 'Gestione piattaforma']),

                Filter::make('ultime_24h')
                    ->label('Ultime 24 ore')
                    ->query(fn (Builder $query) => $query->where('created_at', '>=', now()->subDay())),
            ])
            // Nessuna azione: un registro che si puo' correggere non e' un
            // registro. La potatura la fa `slimcms:controlla-accessi`.
            ->recordActions([])
            ->toolbarActions([])
            ->emptyStateHeading('Nessun accesso registrato')
            ->emptyStateDescription('Qui compaiono gli ingressi ai due pannelli e i tentativi non riusciti.');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListAccessi::route('/')];
    }
}
