<?php

namespace App\ControlPlane\Filament\Resources\Sites;

use App\ControlPlane\Filament\Resources\Sites\RelationManagers\RedattoriRelationManager;
use App\Models\Impersonazione;
use App\Models\User;
use Filament\Actions\Action as AzioneRiga;
use App\Models\Page;
use App\Models\Site;
use App\Enums\StatoSito;
use App\Services\StatoDominio;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
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
        // Qui resta il CICLO DI VITA del sito — a chi appartiene, che
        // indirizzo ha, come sta il dominio — e niente di piu'. Come si
        // presenta, cosa mostra e con chi parla lo decide chi il sito lo
        // abita, da «Impostazioni del sito» nel pannello del sito: testata,
        // footer, blog, area webmaster, statistiche, immagine di
        // condivisione, favicon, moduli e verifica anti-spam sono tutti la'.
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
                    ->helperText('Puoi incollarlo come capita: schema, www e maiuscole li togliamo noi. '
                        . 'Es: cliente.it oppure blog.cliente.it')
                    // Si normalizza uscendo dal campo, non al salvataggio: la
                    // regola `regex` gira sullo stato del campo, quindi uno
                    // spazio di troppo o una maiuscola diventavano "formato
                    // non valido" invece di essere semplicemente tolti.
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (?string $state, callable $set, ?Site $record) => $set(
                        'domain',
                        self::normalizzaDominio($state, $record)
                    ))
                    ->dehydrateStateUsing(fn (?string $state, ?Site $record): ?string => self::normalizzaDominio($state, $record)),

                TextInput::make('name')
                    ->label('Nome del sito')
                    ->required()
                    ->maxLength(190)
                    // Serve alla creazione — un sito senza nome non si puo'
                    // fare — ma dopo e' affare di chi il sito lo abita, come
                    // tutto il resto della sua configurazione. Si cambia da
                    // «Impostazioni del sito», nel pannello del sito.
                    ->visibleOn('create'),
            ])->columns(2),

            Section::make('Stato del sito')
                ->description('Se il sito e\' online, in attesa o fermo. Lo decide la piattaforma: '
                    . 'chi abita il sito cambia quello che il sito mostra, non se il sito c\'e\'.')
                ->schema([
                    Radio::make('stato')
                        ->label('Stato')
                        ->options(StatoSito::opzioni())
                        ->descriptions(collect(StatoSito::cases())
                            ->mapWithKeys(fn (StatoSito $x) => [$x->value => $x->descrizione()])->all())
                        ->default(StatoSito::Attivo->value)
                        ->required()
                        ->live(),

                    TextInput::make('nota_cortesia')
                        ->label('Riga in piu\' sulla pagina di attesa')
                        ->maxLength(200)
                        ->placeholder('Torniamo online il 12 marzo')
                        ->visible(fn (Get $get): bool => $get('stato') !== StatoSito::Attivo->value)
                        // Il motivo non si scrive mai: e' un fatto privato del
                        // cliente, e la pagina e' pubblica.
                        ->helperText('Facoltativa, e visibile a chiunque: non scriverci il motivo.'),
                ])->columns(1),

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
    /**
     * Il dominio come deve stare in `sites.domain`: minuscolo, senza spazi,
     * senza schema e **senza `www.`**.
     *
     * Il `www.` non e' cosmetica. `RisolviSitoDaParametro` toglie il `www.`
     * dall'indirizzo che arriva e lo confronta con questa colonna: un sito
     * salvato come `www.cliente.it` non verrebbe trovato da nessuna
     * richiesta, e il sintomo sarebbe un 404 su tutto senza niente di rotto
     * da nessuna parte. Il campo lo diceva soltanto nel testo d'aiuto.
     */
    public static function normalizzaDominio(?string $stato, ?Site $record = null): ?string
    {
        $pulito = mb_strtolower(trim((string) $stato));

        // Anche lo schema, se qualcuno incolla l'indirizzo dalla barra del
        // browser invece di scriverlo.
        $pulito = preg_replace('#^[a-z]+://#', '', $pulito) ?? $pulito;
        $pulito = rtrim($pulito, '/');

        if (str_starts_with($pulito, 'www.')) {
            $pulito = substr($pulito, 4);
        }

        return $pulito !== '' ? $pulito : $record?->domain;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('domain')->label('Dominio')->searchable()->sortable()->weight('medium')
                    ->url(fn (Site $record): string => 'https://' . $record->domain)
                    ->openUrlInNewTab(),

                // Lo stato prima del nome: se un sito e' fermo, e' la prima
                // cosa da sapere guardando l'elenco.
                TextColumn::make('stato')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (Site $record): string => $record->statoSito()->etichetta())
                    ->color(fn (Site $record): string => $record->statoSito()->colore())
                    // Un sito attivo e' la normalita': mostrare un badge verde
                    // su ogni riga fa rumore e basta.
                    ->visible(fn (): bool => Site::withoutTenancy()->where('stato', '!=', StatoSito::Attivo->value)->exists()),

                TextColumn::make('name')->label('Nome')->searchable()->color('gray')
                    // Su telefono restano dominio e certificato: il resto e'
                    // contesto, e sei colonne su 375px sono illeggibili.
                    ->visibleFrom('md'),

                TextColumn::make('tenant.name')->label('Cliente')->badge()->sortable()
                    ->visibleFrom('lg'),

                TextColumn::make('users_count')->label('Redattori')->counts('users')->badge()
                    ->visibleFrom('md')
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
                    // Con un redattore solo l'etichetta dice gia' di chi
                    // prenderai l'identita': non e' un dettaglio estetico,
                    // e' l'unica informazione che conta prima del clic.
                    ->label(fn (Site $record): string => $record->users->count() === 1
                        ? 'Apri come ' . $record->users->first()->name
                        : 'Apri il pannello contenuti')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->color('primary')
                    ->visible(fn (): bool => (bool) auth('manage')->user()?->isSuperAdmin())
                    ->schema(fn (Site $record): array => $record->users->count() < 2 ? [] : [
                        Select::make('user_id')
                            ->label('Entra come')
                            ->options(fn (): array => $record->users->pluck('name', 'id')->all())
                            ->required()
                            ->helperText('Entrerai come questo redattore. L\'accesso resta registrato.'),
                    ])
                    // Una modale che chiede di scegliere fra una cosa sola
                    // non e' una conferma, e' un clic in piu': con un solo
                    // redattore si entra diretti. L'accesso resta comunque
                    // registrato in `impersonazioni` e revocabile.
                    ->modalHidden(fn (Site $record): bool => $record->users->count() < 2)
                    ->modalHeading('Chi vuoi impersonare su questo sito?')
                    ->modalDescription('Le modifiche che farai risulteranno fatte dal redattore scelto, ma resta traccia che dietro c\'eri tu.')
                    ->modalSubmitActionLabel('Entra')
                    ->disabled(fn (Site $record): bool => $record->users->isEmpty())
                    ->action(function (Site $record, array $data) {
                        // Senza modale non arriva nessun user_id: l'unico
                        // redattore del sito e' la scelta implicita.
                        $utente = User::withoutSitePivotScope()
                            ->findOrFail($data['user_id'] ?? $record->users->first()?->getKey());

                        $imp = Impersonazione::apri(
                            auth('manage')->user(),
                            $utente,
                            $record,
                            request()->ip(),
                        );

                        return redirect()->route('impersona.entra', $imp->token);
                    }),

                // Fermare un sito e' una cosa che si fa di fretta: due clic
                // dall'elenco, senza passare dal form.
                Action::make('ferma')
                    ->label(fn (Site $record): string => $record->mostraCortesia() ? 'Riattiva' : 'Sospendi')
                    ->icon(fn (Site $record): string => $record->mostraCortesia()
                        ? 'heroicon-o-play'
                        : 'heroicon-o-pause')
                    ->color(fn (Site $record): string => $record->mostraCortesia() ? 'success' : 'danger')
                    ->requiresConfirmation()
                    ->modalHeading(fn (Site $record): string => $record->mostraCortesia()
                        ? 'Rimettere online ' . $record->domain . '?'
                        : 'Sospendere ' . $record->domain . '?')
                    ->modalDescription(fn (Site $record): string => $record->mostraCortesia()
                        ? 'Il sito torna raggiungibile alla prossima pubblicazione, entro un minuto.'
                        : 'I visitatori vedranno una pagina di attesa. I contenuti restano nel pannello, '
                            . 'e il cliente continua a vederli.')
                    ->action(function (Site $record): void {
                        // Il cambio di stato accoda da solo una build
                        // (SiteObserver): e' quella che riscrive l'.htaccess
                        // e mette — o toglie — la pagina di cortesia.
                        $record->forceFill([
                            'stato' => $record->mostraCortesia()
                                ? StatoSito::Attivo->value
                                : StatoSito::Sospeso->value,
                        ])->save();

                        Notification::make()
                            ->title($record->domain)
                            ->body($record->mostraCortesia()
                                ? 'Sospeso. La pagina di attesa comparira\' entro un minuto.'
                                : 'Riattivato. Il sito torna online entro un minuto.')
                            ->success()
                            ->send();
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
