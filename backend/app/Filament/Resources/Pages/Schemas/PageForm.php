<?php

namespace App\Filament\Resources\Pages\Schemas;

use Filament\Forms\Components\Builder as BlockBuilder;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * Form di redazione di una pagina.
 *
 * Nota: NON c'e' un campo site_id. Il sito arriva dal tenant Filament
 * attivo, che SetCurrentSiteFromFilamentTenant traduce nel binding
 * 'currentSite'; BelongsToSite lo assegna da solo alla creazione. Esporre
 * quel campo significherebbe permettere a un redattore di spostare una
 * pagina su un altro sito scegliendolo da una tendina.
 */
class PageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make()->columnSpanFull()->tabs([

                Tabs\Tab::make('Contenuto')->schema([
                    TextInput::make('title')
                        ->label('Titolo')
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (?string $state, callable $set) => $set('slug', Str::slug((string) $state))),

                    TextInput::make('slug')
                        ->label('Slug')
                        ->required()
                        ->maxLength(255)
                        ->helperText("L'indirizzo della pagina: slimcms.it/<slug>")
                        ->disabled(fn (?\App\Models\Page $record): bool => (bool) $record?->is_home)
                        ->dehydrated(fn (?\App\Models\Page $record): bool => ! $record?->is_home),

                    Toggle::make('is_home')
                        ->label('Pagina iniziale del sito')
                        ->helperText('E la pagina servita sulla radice del dominio. Ce ne puo essere una sola: assegnandola qui, la precedente smette di esserlo.')
                        ->disabled(fn (?\App\Models\Page $record): bool => (bool) $record?->is_home)
                        // Gia' home: l'interruttore resta acceso ma non si
                        // spegne. Spegnerlo lascerebbe il sito senza pagina
                        // iniziale, e il modo giusto di cambiarla e'
                        // promuoverne un'altra.
                        ->dehydrated(fn (?\App\Models\Page $record): bool => ! $record?->is_home),

                    self::blocchi(),
                ])->columns(1),

                Tabs\Tab::make('Pubblicazione')->schema([
                    Select::make('status')
                        ->label('Stato')
                        ->options([
                            'draft' => 'Bozza',
                            'published' => 'Pubblicata',
                            'scheduled' => 'Programmata',
                        ])
                        ->default('draft')
                        ->required()
                        ->live(),

                    DateTimePicker::make('publish_at')
                        ->label('Data di pubblicazione')
                        ->seconds(false)
                        ->visible(fn (callable $get) => $get('status') === 'scheduled')
                        ->requiredIf('status', 'scheduled'),
                ])->columns(2),

                ...self::tabSeoGeoAeo(),
            ]),
        ]);
    }

    /**
     * I tab SEO / GEO / AEO, condivisi fra Page e Post.
     *
     * Stanno qui e non duplicati nei due form perche' un campo aggiunto in un
     * posto e dimenticato nell'altro e' una divergenza che nessuno nota
     * finche' non manca un dato in produzione.
     *
     * @return array<int, mixed>
     */
    public static function tabSeoGeoAeo(): array
    {
        return [
                    Tabs\Tab::make('SEO')->schema([
                        TextInput::make('seo.meta_title')
                            ->label('Titolo per i motori')
                            ->maxLength(60)
                            ->live(debounce: 400)
                            ->helperText(fn (?string $state) => self::contatore($state, 50, 60)),

                        Textarea::make('seo.meta_description')
                            ->label('Descrizione per i motori')
                            ->rows(3)
                            ->maxLength(160)
                            ->live(debounce: 400)
                            ->helperText(fn (?string $state) => self::contatore($state, 120, 160)),

                        TextInput::make('seo.canonical_url')
                            ->label('URL canonico')
                            ->url()
                            ->helperText('Lascia vuoto se questa e\' la versione originale della pagina.'),

                        Toggle::make('seo.noindex')
                            ->label('Escludi dai motori di ricerca (noindex)')
                            ->helperText('Da usare con cautela: la pagina sparisce dai risultati.'),
                    ])->columns(1),

                    // GEO: Generative Engine Optimization. Non sono campi SEO
                    // classici, servono a farsi citare da Perplexity, AI Overview
                    // e ChatGPT Search, che privilegiano sintesi e affermazioni
                    // fattuali isolabili.
                    Tabs\Tab::make('GEO')->schema([
                        Textarea::make('seo.structured_summary')
                            ->label('Sintesi per i motori generativi')
                            ->rows(3)
                            ->maxLength(400)
                            ->live(debounce: 400)
                            ->helperText(fn (?string $state) => 'Due o tre frasi che riassumono la pagina in linguaggio naturale. '.self::contatore($state, 150, 400)),

                        Repeater::make('seo.key_facts')
                            ->label('Fatti chiave')
                            ->helperText('Affermazioni verificabili e autoportanti: i motori generativi tendono a citare frasi dichiarative isolabili.')
                            ->simple(
                                TextInput::make('fatto')->required()->maxLength(300)
                            )
                            ->addActionLabel('Aggiungi un fatto')
                            ->defaultItems(0)
                            ->reorderable(),
                    ])->columns(1),

                    // AEO: Answer Engine Optimization. Risposte dirette e FAQ
                    // strutturate, da cui si genera il markup Schema.org FAQPage.
                    Tabs\Tab::make('AEO')->schema([
                        Select::make('seo.schema_type')
                            ->label('Tipo di contenuto (Schema.org)')
                            ->options([
                                'Article' => 'Articolo',
                                'Organization' => 'Organizzazione',
                                'LocalBusiness' => 'Attivita locale',
                                'Product' => 'Prodotto',
                                'HowTo' => 'Guida passo passo',
                                'SoftwareApplication' => 'Software',
                            ])
                            ->helperText('Determina il markup strutturato generato automaticamente.'),

                        Textarea::make('seo.direct_answer')
                            ->label('Risposta diretta')
                            ->rows(3)
                            ->maxLength(300)
                            ->helperText('Solo per pagine che rispondono a una domanda precisa ("Cos\'e...", "Come si fa..."). Pensata per essere estratta come featured snippet o risposta vocale.'),

                        Repeater::make('seo.faq_block')
                            ->label('FAQ')
                            ->helperText('Genera automaticamente il markup Schema.org FAQPage.')
                            ->schema([
                                TextInput::make('domanda')->required()->maxLength(300),
                                Textarea::make('risposta')->required()->rows(3),
                            ])
                            ->addActionLabel('Aggiungi una domanda')
                            ->defaultItems(0)
                            ->collapsed()
                            ->itemLabel(fn (array $state): ?string => $state['domanda'] ?? null),
                    ])->columns(1),
        ];
    }

    /**
     * Il builder a blocchi, condiviso fra Page e Post.
     */
    public static function blocchiPubblici(): BlockBuilder
    {
        return self::blocchi();
    }

    /**
     * Builder a blocchi: il corpo della pagina.
     */
    private static function blocchi(): BlockBuilder
    {
        return BlockBuilder::make('blocks')
            ->label('Contenuto della pagina')
            ->addActionLabel('Aggiungi un blocco')
            ->collapsible()
            ->blockNumbers(false)
            ->blocks([
                BlockBuilder\Block::make('hero')
                    ->label('Apertura')
                    ->icon('heroicon-o-megaphone')
                    ->schema([
                        TextInput::make('occhiello')->label('Occhiello')->maxLength(80),
                        TextInput::make('titolo')->label('Titolo')->required(),
                        Textarea::make('testo')->label('Testo')->rows(3),
                    ]),

                BlockBuilder\Block::make('testo_ricco')
                    ->label('Testo')
                    ->icon('heroicon-o-document-text')
                    ->schema([
                        RichEditor::make('corpo')->label('Corpo')->required(),
                    ]),

                BlockBuilder\Block::make('galleria')
                    ->label('Galleria')
                    ->icon('heroicon-o-photo')
                    ->schema([
                        TextInput::make('titolo')->label('Titolo'),
                        SpatieMediaLibraryFileUpload::make('immagini')
                            ->label('Immagini')
                            ->collection('immagini')
                            ->multiple()
                            ->reorderable()
                            ->image()
                            ->maxSize(8192)
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->helperText('Massimo 8 MB per immagine. Il testo alternativo si imposta dalla libreria media.'),
                    ]),

                BlockBuilder\Block::make('cta')
                    ->label('Invito all\'azione')
                    ->icon('heroicon-o-cursor-arrow-rays')
                    ->schema([
                        TextInput::make('titolo')->label('Titolo')->required(),
                        TextInput::make('etichetta_bottone')->label('Testo del bottone')->required(),
                        TextInput::make('url')->label('Destinazione')->required(),
                    ]),

                // Elenco di capacita' a tre colonne: etichetta, testo e una
                // riga tecnica. E' usato dalla home di slimcms.it; senza
                // questo blocco quel contenuto sarebbe visibile sul sito ma
                // non modificabile dal pannello.
                BlockBuilder\Block::make('capacita')
                    ->label('Elenco di capacita')
                    ->icon('heroicon-o-list-bullet')
                    ->schema([
                        Repeater::make('voci')
                            ->label('Voci')
                            ->schema([
                                TextInput::make('etichetta')->label('Etichetta')->required()->maxLength(40),
                                TextInput::make('titolo')->label('Titolo')->required(),
                                Textarea::make('testo')->label('Testo')->rows(3)->required(),
                                TextInput::make('macchina')
                                    ->label('Riga tecnica')
                                    ->helperText('Il frammento in monospazio accanto alla voce. Facoltativo.'),
                            ])
                            ->defaultItems(1)
                            ->collapsed()
                            ->itemLabel(fn (array $state): ?string => $state['titolo'] ?? null),
                    ]),

                BlockBuilder\Block::make('faq')
                    ->label('Domande frequenti')
                    ->icon('heroicon-o-question-mark-circle')
                    ->schema([
                        Repeater::make('voci')
                            ->label('Domande')
                            ->schema([
                                TextInput::make('domanda')->required(),
                                Textarea::make('risposta')->required()->rows(3),
                            ])
                            ->defaultItems(1),
                    ]),
            ]);
    }

    /**
     * Contatore caratteri con indicazione dell'intervallo consigliato.
     */
    private static function contatore(?string $stato, int $min, int $max): string
    {
        $n = mb_strlen((string) $stato);

        if ($n === 0) {
            return "Vuoto — consigliati fra {$min} e {$max} caratteri.";
        }

        if ($n < $min) {
            return "{$n} caratteri — corto, ne servirebbero almeno {$min}.";
        }

        if ($n > $max) {
            return "{$n} caratteri — oltre {$max}, verra' troncato nei risultati.";
        }

        return "{$n} caratteri — buona lunghezza.";
    }
}
