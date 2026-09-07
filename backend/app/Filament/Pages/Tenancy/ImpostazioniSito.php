<?php

namespace App\Filament\Pages\Tenancy;

use App\Models\Page;
use App\Models\Site;
use App\Services\GeneratoreOpenGraph;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Forms\Components\Placeholder;
use Illuminate\Support\HtmlString;

/**
 * Le impostazioni del singolo sito, dentro il pannello di quel sito.
 *
 * **Tutto quello che riguarda come si presenta il sito sta qui.** Stavano nel
 * control plane, in mezzo al cliente e al dominio: cambiare la propria
 * testata voleva dire chiederlo a noi. Nel control plane resta il ciclo di
 * vita del sito — crearlo, sospenderlo, cancellarlo — e lo stato del dominio;
 * il resto lo decide chi il sito lo abita.
 *
 * E' anche il motivo per cui il nome del sito e' modificabile qui e nel
 * control plane compare solo alla creazione: un sito senza nome non si puo'
 * creare, ma dopo il nome e' suo.
 *
 * Il ruolo richiesto e' `admin` sul sito, e passa da `SitePolicy`:
 * `EditTenantProfile::canView()` chiede `authorize('update', $tenant)`.
 */
class ImpostazioniSito extends EditTenantProfile
{
    public static function getLabel(): string
    {
        return 'Impostazioni del sito';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Il sito')
                ->schema([
                    TextInput::make('name')
                        ->label('Nome del sito')
                        ->required()
                        ->maxLength(190)
                        ->helperText('Compare nella testata, nel titolo della scheda del browser e '
                            . 'nelle anteprime social. Se non hai scelto delle iniziali, la favicon '
                            . 'si ricava da qui.'),
                ]),

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

            Section::make('Modulo di contatto')
                ->description('Dove arrivano i messaggi scritti dai visitatori.')
                ->schema([
                    TextInput::make('contact_email')
                        ->label('Email del destinatario')
                        ->email()
                        ->maxLength(180)
                        ->helperText('I messaggi restano comunque nel pannello, sotto "Messaggi": '
                            . 'questa e\' solo la notifica. Se la lasci vuota non parte nessuna email.'),
                ]),

            Section::make('Verifica anti-spam')
                ->description('Come il sito distingue una persona da un bot che compila moduli a tappeto.')
                ->schema([
                    Radio::make('captcha_fornitore')
                        ->label('Fornitore')
                        ->options(\App\Support\Captcha\FabbricaCaptcha::FORNITORI)
                        ->default('semplice')
                        ->live()
                        ->helperText('La domanda semplice non richiede nessun account e non manda dati a terzi. '
                            . 'Gli altri due sono piu\' efficaci su un sito molto bersagliato.'),

                    TextInput::make('captcha_chiave_pubblica')
                        ->label('Chiave del sito')
                        ->maxLength(200)
                        ->visible(fn (Get $get): bool => in_array($get('captcha_fornitore'), ['turnstile', 'recaptcha'], true))
                        ->helperText('La chiave pubblica, quella che finisce nella pagina.'),

                    TextInput::make('captcha_segreto')
                        ->label('Chiave segreta')
                        ->password()
                        ->revealable()
                        ->maxLength(200)
                        ->visible(fn (Get $get): bool => in_array($get('captcha_fornitore'), ['turnstile', 'recaptcha'], true))
                        // Non esce mai dal backend e resta cifrata nel
                        // database: e' quella con cui si verifica.
                        ->helperText('Resta qui, cifrata. Non compare mai nel sito.'),
                ])->columns(2),

            Section::make('Favicon')
                ->description('L\'icona che compare nella scheda del browser e fra i preferiti.')
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
                                'name' => $record?->name ?? 'Sito',
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
                        // Niente SVG. Un SVG e' un documento, non
                        // un'immagine: puo' portare riferimenti a file del
                        // server (`<image xlink:href="text:...">`), e il
                        // rasterizzatore quei riferimenti li segue. Vedi
                        // GeneratoreFavicon::fileCaricato(). Il vettoriale
                        // per la favicon lo generiamo noi.
                        ->acceptedFileTypes(['image/png', 'image/x-icon', 'image/jpeg', 'image/webp'])
                        ->directory('favicon')
                        ->visible(fn (Get $get) => $get('favicon_modo') === 'caricata')
                        ->helperText('PNG, ICO, JPEG o WebP, massimo 512 KB. Consigliato quadrato, almeno 128x128. '
                            . 'Il file resta qui: il sito pubblica una copia in /favicon.ico, generata da questa immagine.')
                        // Passando a "generata" il file va tolto, altrimenti
                        // resterebbe e continuerebbe ad avere la precedenza.
                        ->dehydrateStateUsing(fn ($state, Get $get) => $get('favicon_modo') === 'caricata' ? $state : null),
                ])->columns(2),
        ]);
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
