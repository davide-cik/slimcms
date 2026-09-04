<?php

namespace App\ControlPlane\Filament\Resources\Sites;

use App\ControlPlane\Filament\Resources\Sites\RelationManagers\RedattoriRelationManager;
use App\Models\Impersonazione;
use App\Models\User;
use Filament\Actions\Action as AzioneRiga;
use App\Models\Site;
use App\Services\StatoDominio;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Forms\Components\Placeholder;
use Illuminate\Support\HtmlString;
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
                    ->dehydrateStateUsing(fn (?string $state, ?Site $record): ?string => self::normalizzaDominio($state, $record)),

                TextInput::make('name')
                    ->label('Nome del sito')
                    ->required()
                    ->maxLength(190),
            ])->columns(2),

            Section::make('Favicon')
                ->description('L\'icona che compare nella scheda del browser.')
                ->schema([
                    Radio::make('favicon_modo')
                        ->label('Come ottenerla')
                        ->options([
                            'generata' => 'Genera dalle iniziali',
                            'caricata' => 'Carica un file',
                        ])
                        ->default(fn (?Site $record) => filled($record?->favicon_path) ? 'caricata' : 'generata')
                        ->dehydrated(false)
                        ->live()
                        ->inline()
                        ->inlineLabel(false),

                    TextInput::make('favicon_initials')
                        ->label('Iniziali')
                        ->maxLength(3)
                        ->placeholder(fn (?Site $record) => $record?->faviconIniziali() ?? '')
                        ->helperText('Massimo 3 lettere. Se lo lasci vuoto le ricaviamo dal nome del sito.')
                        ->visible(fn (Get $get) => $get('favicon_modo') !== 'caricata')
                        ->live(onBlur: true)
                        ->dehydrateStateUsing(fn (?string $state): ?string => filled($state)
                            ? mb_strtoupper(trim($state))
                            : null),

                    // L'anteprima e' l'SVG vero, non una simulazione: se qui
                    // si vede storto, si vedra' storto anche nella scheda.
                    Placeholder::make('favicon_anteprima')
                        ->label('Anteprima')
                        ->visible(fn (Get $get) => $get('favicon_modo') !== 'caricata')
                        ->content(function (Get $get, ?Site $record): HtmlString {
                            $finto = new Site([
                                'name' => $get('name') ?: ($record?->name ?? 'Sito'),
                                'favicon_initials' => $get('favicon_initials'),
                            ]);
                            $finto->theme = $record?->theme ?? [];

                            return new HtmlString(
                                '<div style="width:64px;height:64px">' . $finto->faviconSvg() . '</div>'
                            );
                        }),

                    FileUpload::make('favicon_path')
                        ->label('File')
                        ->image()
                        ->imageEditor()
                        ->maxSize(512)
                        ->acceptedFileTypes(['image/svg+xml', 'image/png', 'image/x-icon'])
                        ->directory('favicon')
                        ->visible(fn (Get $get) => $get('favicon_modo') === 'caricata')
                        ->helperText('SVG, PNG o ICO, massimo 512 KB. Consigliato quadrato, almeno 128x128.')
                        // Passando a "generata" il file va tolto, altrimenti
                        // resterebbe e continuerebbe ad avere la precedenza.
                        ->dehydrateStateUsing(fn ($state, Get $get) => $get('favicon_modo') === 'caricata' ? $state : null),
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

    /**
     * Normalizza un dominio, senza mai poterlo svuotare.
     *
     * E' successo davvero: con un parametro di closure che Filament non
     * sapeva risolvere, qui arrivava null, il risultato era stringa vuota e
     * il dominio veniva sovrascritto al primo salvataggio del sito. La
     * validazione 'required' non protegge, perche' gira PRIMA di questa
     * trasformazione: il valore era valido quando e' stato validato ed e'
     * stato svuotato dopo.
     *
     * Sta in un metodo e non dentro la closure per poterlo testare.
     */
    public static function normalizzaDominio(?string $stato, ?Site $record = null): ?string
    {
        $pulito = mb_strtolower(trim((string) $stato));

        return $pulito !== '' ? $pulito : $record?->domain;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('domain')->label('Dominio')->searchable()->sortable()->weight('medium')
                    ->url(fn (Site $record): string => 'https://' . $record->domain)
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
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'valido' => 'valido',
                        'in_scadenza' => 'in scadenza',
                        'scaduto' => 'SCADUTO',
                        'irraggiungibile' => 'irraggiungibile',
                        'da_configurare' => 'da configurare',
                        default => $s ?? '—',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'valido' => 'success',
                        'in_scadenza' => 'warning',
                        'scaduto', 'fallito' => 'danger',
                        default => 'gray',
                    })
                    ->description(fn (Site $record): ?string => $record->ssl_expires_at?->format('d/m/Y')),

                TextColumn::make('dns_status')->label('DNS')->badge()
                    ->color(fn (?string $state): string => $state === 'ok' ? 'success' : 'warning')
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

                // Entra nel pannello contenuti impersonando un redattore.
                // Non e' un accesso diretto del super-admin: vedi
                // ImpersonazioneController per il perche'.
                Action::make('entra')
                    ->label('Apri il pannello contenuti')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->color('primary')
                    ->visible(fn (): bool => (bool) auth('manage')->user()?->isSuperAdmin())
                    ->schema(fn (Site $record): array => [
                        Select::make('user_id')
                            ->label('Entra come')
                            ->options(fn (): array => $record->users()->pluck('name', 'users.id')->all())
                            ->required()
                            ->helperText('Entrerai come questo redattore. L\'accesso resta registrato.'),
                    ])
                    ->modalHeading('Aprire il pannello contenuti di questo sito?')
                    ->modalDescription('Le modifiche che farai risulteranno fatte dal redattore scelto, ma resta traccia che dietro c\'eri tu.')
                    ->modalSubmitActionLabel('Entra')
                    ->disabled(fn (Site $record): bool => $record->users()->doesntExist())
                    ->action(function (Site $record, array $data) {
                        $utente = User::withoutSitePivotScope()->findOrFail($data['user_id']);

                        $imp = Impersonazione::apri(
                            auth('manage')->user(),
                            $utente,
                            $record,
                            request()->ip(),
                        );

                        return redirect()->route('impersona.entra', $imp->token);
                    }),

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
