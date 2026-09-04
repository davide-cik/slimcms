<?php

namespace App\ControlPlane\Filament\Resources\Sites;

use App\ControlPlane\Filament\Resources\Sites\RelationManagers\RedattoriRelationManager;
use App\Models\Site;
use App\Services\StatoDominio;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Creazione e assegnazione dei siti, dal control plane.
 *
 * E' qui e non nel pannello dei siti perche' creare un sito e' un atto di
 * piattaforma: decide sotto quale cliente nasce e quindi a chi appartengono
 * i suoi contenuti. Un redattore non deve poterlo fare.
 */
class SiteResource extends Resource
{
    protected static ?string $model = Site::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static ?string $modelLabel = 'sito';

    protected static ?string $pluralModelLabel = 'siti';

    protected static ?string $navigationLabel = 'Siti';

    protected static ?string $recordTitleAttribute = 'domain';

    /**
     * Il global scope di stancl e' inerte qui (nessuna tenancy inizializzata
     * nel control plane), quindi il filtro per operatore va messo a mano:
     * un operatore di assistenza vede solo i siti dei clienti assegnati.
     */
    public static function getEloquentQuery(): Builder
    {
        $utente = auth('manage')->user();

        if ($utente === null || $utente->isSuperAdmin()) {
            return parent::getEloquentQuery();
        }

        return parent::getEloquentQuery()
            ->whereIn('tenant_id', $utente->tenants()->pluck('tenants.id'));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Sito')->schema([
                Select::make('tenant_id')
                    ->label('Cliente')
                    ->relationship('tenant', 'name')
                    ->required()
                    ->searchable()
                    ->preload()
                    // Spostare un sito da un cliente all'altro porterebbe con
                    // se' tutti i contenuti e i redattori: non e' un'operazione
                    // da tendina.
                    ->disabledOn('edit')
                    ->helperText('Non modificabile dopo la creazione: sposterebbe contenuti e redattori.'),

                TextInput::make('domain')
                    ->label('Dominio')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(190)
                    ->rule('regex:/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)+$/')
                    ->helperText('Senza http:// e senza www. Es: cliente.it oppure blog.cliente.it')
                    ->dehydrateStateUsing(fn (?string $s): string => mb_strtolower(trim((string) $s))),

                TextInput::make('name')
                    ->label('Nome del sito')
                    ->required()
                    ->maxLength(190),
            ])->columns(2),

            Section::make('Stato del dominio')
                ->visibleOn('edit')
                ->schema([
                    TextInput::make('dns_status')->label('DNS')->disabled(),
                    TextInput::make('ssl_status')->label('Certificato')->disabled(),
                    TextInput::make('ssl_expires_at')->label('Scadenza certificato')->disabled(),
                    TextInput::make('ssl_last_error')->label('Ultimo errore')->disabled()->columnSpanFull(),
                ])->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('domain')->label('Dominio')->searchable()->sortable()->weight('medium')
                    ->url(fn (Site $r): string => 'https://' . $r->domain)
                    ->openUrlInNewTab(),

                TextColumn::make('name')->label('Nome')->searchable()->color('gray'),

                TextColumn::make('tenant.name')->label('Cliente')->badge()->sortable(),

                TextColumn::make('users_count')->label('Redattori')->counts('users')->badge()
                    ->color(fn (int $state): string => $state === 0 ? 'warning' : 'gray')
                    // Un sito senza redattori non e' amministrabile da nessuno:
                    // e' uno stato valido subito dopo la creazione, ma se resta
                    // cosi' e' un cliente che non e' mai partito.
                    ->tooltip(fn (int $state): ?string => $state === 0
                        ? 'Nessuno puo\' amministrare questo sito'
                        : null),

                TextColumn::make('ssl_status')
                    ->label('Certificato')
                    ->badge()
                    ->formatStateUsing(fn (?string $s): string => match ($s) {
                        'valido' => 'valido',
                        'in_scadenza' => 'in scadenza',
                        'scaduto' => 'SCADUTO',
                        'irraggiungibile' => 'irraggiungibile',
                        'da_configurare' => 'da configurare',
                        default => $s ?? '—',
                    })
                    ->color(fn (?string $s): string => match ($s) {
                        'valido' => 'success',
                        'in_scadenza' => 'warning',
                        'scaduto', 'fallito' => 'danger',
                        default => 'gray',
                    })
                    ->description(fn (Site $r): ?string => $r->ssl_expires_at?->format('d/m/Y')),

                TextColumn::make('dns_status')->label('DNS')->badge()
                    ->color(fn (?string $s): string => $s === 'ok' ? 'success' : 'warning')
                    ->toggleable(),
            ])
            ->defaultSort('domain')
            ->filters([
                SelectFilter::make('tenant')->label('Cliente')->relationship('tenant', 'name'),
                SelectFilter::make('ssl_status')->label('Certificato')->options([
                    'valido' => 'Valido',
                    'in_scadenza' => 'In scadenza',
                    'scaduto' => 'Scaduto',
                    'da_configurare' => 'Da configurare',
                ]),
            ])
            ->recordActions([
                EditAction::make(),

                Action::make('verifica')
                    ->label('Verifica dominio')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->action(function (Site $record) {
                        $r = app(StatoDominio::class)->aggiorna($record);

                        Notification::make()
                            ->title($record->domain)
                            ->body('DNS: ' . $r['dns']['stato'] . ' — TLS: ' . $r['cert']['dettaglio'])
                            ->status($r['cert']['stato'] === 'valido' && $r['dns']['stato'] === 'ok' ? 'success' : 'warning')
                            ->send();
                    }),
            ])
            // Nessuna azione di massa: cancellare siti porta via a cascata
            // pagine, articoli e media.
            ->toolbarActions([]);
    }

    public static function getRelations(): array
    {
        return [
            RedattoriRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSites::route('/'),
            'create' => Pages\CreateSite::route('/create'),
            'edit' => Pages\EditSite::route('/{record}/edit'),
        ];
    }
}
