<?php

namespace App\ControlPlane\Filament\Resources\Sites;

use App\ControlPlane\Filament\Resources\Sites\RelationManagers\RedattoriRelationManager;
use App\Models\Impersonazione;
use App\Models\User;
use Filament\Actions\Action as AzioneRiga;
use Filament\Forms\Components\Textarea;
use App\Services\GeneratoreOpenGraph;
use App\Models\Page;
use App\Models\Site;
use App\Services\StatoDominio;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Radio;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Forms\Components\Placeholder;
use Illuminate\Support\HtmlString;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Toggle;
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

            // La favicon non sta piu' qui: e' identita' del sito, e si
            // cambia dal pannello di quel sito
            // (App\Filament\Pages\Tenancy\ImpostazioniSito). Qui restano
            // dominio, piano e stato, che sono affari della piattaforma.

            Section::make('Testata')
                ->description('Il marchio e il menu in cima a ogni pagina del sito.')
                ->schema([
                    Radio::make('layout_config.tipo')
                        ->label('Disposizione')
                        ->options([
                            'semplice' => 'Semplice — marchio a sinistra, menu a destra',
                            'centrata' => 'Centrata — marchio sopra, menu sotto',
                            'divisa' => 'Divisa — meta menu, marchio, meta menu',
                            'compatta' => 'Compatta — solo il marchio, menu dietro un pulsante',
                        ])
                        ->default('semplice')
                        ->columnSpanFull(),

                    Toggle::make('layout_config.fissa')
                        ->label('Resta in alto scorrendo')
                        ->helperText('Su pagine lunghe tiene il menu sempre raggiungibile. Ruba una striscia di schermo: su telefono la testata si riduce da sola.'),

                    Toggle::make('layout_config.mostra_logo')
                        ->label('Mostra il logo accanto al nome')
                        ->default(true),

                    TextInput::make('layout_config.nome_visibile')
                        ->label('Nome mostrato')
                        ->maxLength(60)
                        ->helperText('Vuoto: si usa il nome del sito.'),

                    Repeater::make('layout_config.voci')
                        ->label('Voci di menu')
                        ->columnSpanFull()
                        ->schema([
                            TextInput::make('etichetta')->label('Testo')->required()->maxLength(40),
                            TextInput::make('url')->label('Indirizzo')->required()->maxLength(300)
                                ->helperText('Interno come /chi-siamo/, ancora come /#capacita, oppure completo con https://'),
                            Toggle::make('evidenza')
                                ->label('In evidenza')
                                ->helperText('L\'ultima voce, quella che invita a scrivere o comprare.'),
                        ])
                        ->columns(3)
                        ->defaultItems(0)
                        ->reorderable()
                        ->itemLabel(fn (array $state): ?string => $state['etichetta'] ?? null)
                        ->addActionLabel('Aggiungi voce'),

                    // La riga sottile sopra la testata: telefono, email o un
                    // avviso. Compare solo se almeno un campo e' pieno, cosi'
                    // non resta una striscia vuota su chi non la usa.
                    TextInput::make('layout_config.barra.testo')
                        ->label('Barra di servizio: avviso')
                        ->maxLength(120)
                        ->placeholder('Consegne in 24h in tutta Italia'),

                    TextInput::make('layout_config.barra.telefono')
                        ->label('Barra di servizio: telefono')
                        ->tel()
                        ->maxLength(40),

                    TextInput::make('layout_config.barra.email')
                        ->label('Barra di servizio: email')
                        ->email()
                        ->maxLength(120),
                ])->columns(2),

            Section::make('Blog')
                ->description('Gli articoli vivono tutti sotto un segmento dell\'indirizzo. Gli archivi di categoria e tag ci stanno dentro.')
                ->schema([
                    TextInput::make('layout_config.blog.base')
                        ->label('Segmento del blog')
                        ->default('blog')
                        ->maxLength(40)
                        ->prefix('/')
                        ->suffix('/')
                        ->rule('regex:/^[a-z0-9-]{1,40}$/')
                        ->validationMessages(['regex' => 'Solo lettere minuscole, numeri e trattini.'])
                        ->helperText('blog, news, articoli... Cambiarlo sposta TUTTI gli indirizzi degli articoli: se il sito e gia pubblico, aggiungi prima i reindirizzamenti.')
                        // Un segmento uguale allo slug di una pagina renderebbe
                        // quella pagina irraggiungibile: vincono gli articoli.
                        ->rules([
                            fn (?Site $record) => function (string $attributo, $valore, \Closure $fallisce) use ($record) {
                                if ($record === null || blank($valore)) {
                                    return;
                                }

                                $scontro = Page::withoutSiteScope()
                                    ->where('site_id', $record->id)
                                    ->where('slug', trim((string) $valore, '/'))
                                    ->exists();

                                if ($scontro) {
                                    $fallisce('Esiste gia una pagina con questo indirizzo: gli articoli la coprirebbero.');
                                }
                            },
                        ]),
                ])->columns(2),

            Section::make('Footer')
                ->description('Cosa compare in fondo a ogni pagina del sito.')
                ->schema([
                    Radio::make('footer_config.tipo')
                        ->label('Tipo')
                        ->options([
                            'semplice' => 'Semplice — solo firma e dati legali',
                            'colonne' => 'A colonne — con elenchi di collegamenti',
                        ])
                        ->default('semplice')
                        ->live()
                        ->inline()
                        ->inlineLabel(false)
                        ->columnSpanFull(),

                    Select::make('footer_config.colonne')
                        ->label('Numero di colonne')
                        ->options([1 => 'Una', 2 => 'Due', 3 => 'Tre'])
                        ->default(3)
                        ->live()
                        ->visible(fn (Get $get): bool => $get('footer_config.tipo') === 'colonne')
                        ->helperText('Su telefono le colonne si impilano comunque: sotto i 480px affiancarle le renderebbe illeggibili.'),

                    Repeater::make('footer_config.blocchi')
                        ->label('Colonne')
                        ->visible(fn (Get $get): bool => $get('footer_config.tipo') === 'colonne')
                        ->columnSpanFull()
                        // Il numero di colonne decide quante se ne possono
                        // riempire: piu' blocchi che colonne sarebbe contenuto
                        // scritto e mai mostrato.
                        ->maxItems(fn (Get $get): int => (int) ($get('footer_config.colonne') ?? 3))
                        ->schema([
                            TextInput::make('titolo')->label('Titolo')->required()->maxLength(60),
                            Repeater::make('voci')
                                ->label('Collegamenti')
                                ->schema([
                                    TextInput::make('etichetta')->label('Testo')->required()->maxLength(60),
                                    TextInput::make('url')->label('Indirizzo')->required()->maxLength(300)
                                        ->helperText('Interno come /chi-siamo, oppure completo con https://'),
                                ])
                                ->columns(2)
                                ->defaultItems(1)
                                ->addActionLabel('Aggiungi collegamento'),
                        ])
                        ->defaultItems(0)
                        ->collapsed()
                        ->itemLabel(fn (array $state): ?string => $state['titolo'] ?? null)
                        ->addActionLabel('Aggiungi colonna'),

                    TextInput::make('footer_config.descrizione')
                        ->label('Descrizione accanto al nome')
                        ->maxLength(120)
                        ->placeholder('piattaforma CMS multitenant'),

                    Toggle::make('footer_config.firma')
                        ->label('Mostra la firma con le icone')
                        ->default(true)
                        ->helperText('La riga "realizzata con ... in Italia da".'),

                    TextInput::make('footer_config.organizzazione')
                        ->label('Chi ha realizzato il sito')
                        ->maxLength(120)
                        ->placeholder('Content is King Srl')
                        ->visible(fn (Get $get): bool => (bool) $get('footer_config.firma')),

                    Textarea::make('footer_config.legale')
                        ->label('Riga legale')
                        ->rows(2)
                        ->maxLength(300)
                        ->columnSpanFull()
                        ->placeholder('© 2026 Nome · Ragione sociale · indirizzo · P.IVA'),
                ])->columns(2),

            Section::make('Doppio registro')
                ->description('La sezione che mostra al visitatore i dati che leggono i motori generativi. Compare solo nelle pagine che hanno un riassunto strutturato o dei fatti chiave.')
                ->schema([
                    Toggle::make('layout_config.doppio.attivo')
                        ->label('Mostrala nel sito')
                        ->live()
                        ->default(false),

                    TextInput::make('layout_config.doppio.etichetta')
                        ->label('Occhiello')
                        ->maxLength(60)
                        ->placeholder('Questa pagina, due volte')
                        ->visible(fn (Get $get): bool => (bool) $get('layout_config.doppio.attivo')),

                    Textarea::make('layout_config.doppio.testo')
                        ->label('Testo introduttivo')
                        ->rows(3)
                        ->maxLength(600)
                        ->columnSpanFull()
                        ->visible(fn (Get $get): bool => (bool) $get('layout_config.doppio.attivo')),
                ])->columns(2),

            Section::make('Area webmaster')
                ->description('I codici di verifica della proprieta\' del sito. Astro li scrive come meta tag in ogni pagina: e\' il metodo che tutti e tre i motori accettano e l\'unico che non si perde a un cambio di DNS o di hosting.')
                ->schema([
                    TextInput::make('seo_defaults.webmaster.google')
                        ->label('Google Search Console')
                        ->maxLength(120)
                        ->helperText('Solo il contenuto del meta google-site-verification, non il tag intero.')
                        ->placeholder('AbCdEf1234...')
                        // Chi incolla il tag intero non ha sbagliato: e' quello
                        // che Google mostra per primo. Estraiamo il valore
                        // invece di salvare markup che finirebbe escapato.
                        ->dehydrateStateUsing(fn (?string $state): ?string => self::codiceVerifica($state)),

                    TextInput::make('seo_defaults.webmaster.bing')
                        ->label('Bing Webmaster Tools')
                        ->maxLength(120)
                        ->helperText('Il valore del meta msvalidate.01.')
                        ->dehydrateStateUsing(fn (?string $state): ?string => self::codiceVerifica($state)),

                    TextInput::make('seo_defaults.webmaster.yandex')
                        ->label('Yandex Webmaster')
                        ->maxLength(120)
                        ->helperText('Il valore del meta yandex-verification.')
                        ->dehydrateStateUsing(fn (?string $state): ?string => self::codiceVerifica($state)),

                    Placeholder::make('nota_webmaster')
                        ->label('Dopo aver salvato')
                        ->columnSpanFull()
                        ->content('Il meta tag compare online alla prima pubblicazione del sito, non subito: fai una build prima di premere "Verifica" nella console del motore.'),
                ])->columns(3),

            Section::make('Statistiche')
                ->description('Google Analytics 4. Lo script viene scritto nelle pagine solo se qui c\'e\' un ID: un sito senza analytics non paga nessuna richiesta in piu\'.')
                ->schema([
                    TextInput::make('seo_defaults.analytics.ga4')
                        ->label('ID misurazione GA4')
                        ->placeholder('G-XXXXXXXXXX')
                        ->maxLength(20)
                        // Il vecchio UA-, l'ID di stream numerico e l'ID GTM
                        // sono tre cose diverse che si incollano per sbaglio al
                        // posto di questo: meglio dirlo subito che scoprire fra
                        // un mese che non arrivano dati.
                        // Insensibile alle maiuscole in ingresso, canonica in uscita:
                        // rifiutare 'g-abc123' sarebbe pedanteria, non validazione.
                        ->rule('regex:/^G-[A-Z0-9]{6,12}$/i')
                        ->validationMessages(['regex' => 'Deve iniziare con G- (e\' il "measurement ID", non l\'ID stream ne\' un codice GTM).'])
                        ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? strtoupper(trim($state)) : null),

                    Toggle::make('seo_defaults.analytics.anonimizza')
                        ->label('Non registrare l\'indirizzo IP')
                        ->default(true)
                        ->helperText('Aggiunge client_storage e ip anonimo alla configurazione. Consigliato in UE.'),
                ])->columns(2),

            Section::make('Immagine di condivisione')
                ->description('L\'anteprima che compare quando qualcuno condivide una pagina di questo sito.')
                ->schema([
                    Textarea::make('og_config.payoff')
                        ->label('Payoff')
                        ->rows(2)
                        ->maxLength(160)
                        ->helperText('Una riga sotto il titolo. Tienila corta: nell\'anteprima si legge in un istante.'),

                    TextInput::make('og_config.cta')
                        ->label('Invito all\'azione')
                        ->maxLength(40)
                        ->placeholder('Visita il nostro sito'),

                    Textarea::make('og_config.legale')
                        ->label('Riga legale')
                        ->rows(2)
                        ->maxLength(200)
                        ->helperText('In fondo all\'immagine. Su Facebook e LinkedIn viene ritagliata via: mettici solo cio\' che puoi permetterti di perdere.'),

                    TextInput::make('og_config.larghezza')
                        ->label('Larghezza')
                        ->numeric()->minValue(600)->maxValue(2400)
                        ->default(GeneratoreOpenGraph::LARGHEZZA_DEFAULT)
                        ->suffix('px'),

                    TextInput::make('og_config.altezza')
                        ->label('Altezza')
                        ->numeric()->minValue(600)->maxValue(2400)
                        ->default(GeneratoreOpenGraph::ALTEZZA_DEFAULT)
                        ->suffix('px')
                        ->helperText('1600 e\' verticale, adatto a Instagram. 630 e\' orizzontale.'),

                    // Due anteprime, non una: la seconda e' cio' che vedono
                    // davvero Facebook e LinkedIn, che ritagliano al centro.
                    // Mostrare solo la prima farebbe credere che l'immagine
                    // arrivi intera a tutti.
                    Placeholder::make('anteprima_og')
                        ->label('Anteprima')
                        ->visibleOn('edit')
                        ->columnSpanFull()
                        ->content(function (?Site $record): HtmlString {
                            if ($record === null) {
                                return new HtmlString('<p>Salva il sito per vedere l\'anteprima.</p>');
                            }

                            $base = route('anteprima.og', $record) . '?v=' . now()->timestamp;

                            return new HtmlString(<<<HTML
                                <div style="display:grid;gap:1.5rem;align-items:start;
                                            grid-template-columns:repeat(auto-fit,minmax(min(220px,100%),1fr))">
                                  <figure style="margin:0;min-width:0">
                                    <img src="{$base}" alt="" loading="lazy"
                                         style="width:100%;max-width:220px;height:auto;border-radius:6px;border:1px solid #d4d4d8">
                                    <figcaption style="font-size:.75rem;opacity:.7;margin-top:.4rem">
                                      Instagram &middot; immagine intera
                                    </figcaption>
                                  </figure>
                                  <figure style="margin:0;min-width:0">
                                    <img src="{$base}&ritaglio=1" alt="" loading="lazy"
                                         style="width:100%;max-width:360px;height:auto;border-radius:6px;border:1px solid #d4d4d8">
                                    <figcaption style="font-size:.75rem;opacity:.7;margin-top:.4rem">
                                      Facebook, LinkedIn, WhatsApp &middot; ritagliata al centro
                                    </figcaption>
                                  </figure>
                                </div>
                                HTML);
                        }),
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

    /**
     * Accetta sia il codice nudo sia il tag <meta> completo che le console
     * mostrano per primo, e restituisce sempre il solo codice.
     */
    protected static function codiceVerifica(?string $valore): ?string
    {
        $valore = trim((string) $valore);

        if ($valore === '') {
            return null;
        }

        if (preg_match('/content=["\']([^"\']+)["\']/', $valore, $trovato) === 1) {
            return $trovato[1];
        }

        return $valore;
    }
}
