<?php

namespace App\Filament\Resources\Prodotti\Schemas;

use App\Filament\Resources\Pages\Schemas\PageForm;
use App\Support\Euro;
use App\Support\PerSito;
use App\Support\RuoloCorrente;
use App\Support\Slug;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

/**
 * Form di un prodotto.
 *
 * I prezzi si scrivono in euro ("39,90") e si salvano in centesimi: la
 * conversione sta in `Euro`, non sparsa nei campi. Blocchi e tab SEO sono
 * quelli di PageForm, per la stessa ragione per cui li riusa PostForm.
 */
class ProdottoForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make()->columnSpanFull()->tabs([

                Tabs\Tab::make('Prodotto')->schema([
                    TextInput::make('nome')
                        ->label('Nome')
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (?string $state, callable $set) => $set('slug', Slug::da($state))),

                    TextInput::make('slug')
                        ->label('Slug')
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true, modifyRuleUsing: PerSito::regolaUnica(...))
                        ->helperText('L\'indirizzo della scheda: /prodotti/<slug>/'),

                    Textarea::make('descrizione')
                        ->label('Descrizione breve')
                        ->rows(3)
                        ->maxLength(400)
                        ->columnSpanFull()
                        ->helperText('Sotto il nome nella scheda, e nelle anteprime quando il link viene condiviso.'),

                    self::importo('prezzo', 'Prezzo')
                        ->required()
                        ->helperText('IVA inclusa, come lo paga chi compra.'),

                    self::importo('prezzo_barrato', 'Prezzo barrato')
                        ->rule(fn (Get $get): Closure => function (string $attributo, mixed $valore, Closure $fail) use ($get): void {
                            $barrato = Euro::inCentesimi($valore);

                            if ($barrato !== null && $barrato <= (int) Euro::inCentesimi($get('prezzo'))) {
                                $fail('Il prezzo barrato e\' quello «di prima»: deve essere piu\' alto del prezzo.');
                            }
                        })
                        ->helperText('Facoltativo. Compare barrato sopra il prezzo.'),

                    TextInput::make('scorte')
                        ->label('Pezzi in magazzino')
                        ->integer()
                        ->minValue(0)
                        ->default(0)
                        ->required()
                        ->helperText('A zero la scheda mostra «esaurito».'),

                    // Fuori dal builder per la stessa ragione della pagina:
                    // dentro un blocco l'upload cancella la propria chiave e il
                    // blocco non sa piu' quali immagini sono sue.
                    SpatieMediaLibraryFileUpload::make('libreria')
                        ->label('Immagini del prodotto')
                        ->collection('immagini')
                        ->multiple()
                        ->reorderable()
                        ->appendFiles()
                        ->image()
                        ->maxSize(8192)
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                        ->columnSpanFull()
                        ->helperText('La prima e\' la foto principale della scheda. Il testo alternativo si imposta dalla libreria media.'),
                ])->columns(2),

                Tabs\Tab::make('Pagina')->schema([
                    PageForm::blocchiPubblici(),
                ])->columns(1),

                Tabs\Tab::make('Pubblicazione')->schema([
                    Select::make('status')
                        ->label('Stato')
                        ->options([
                            'draft' => 'Bozza',
                            'published' => 'Pubblicato',
                        ])
                        ->default('draft')
                        ->required()
                        // Come in PageForm: chi non puo' pubblicare puo' solo
                        // lasciare lo stato com'e'. Il modello lo rifiuta di
                        // nuovo (PubblicazioneRiservata).
                        ->disableOptionWhen(fn (string $value, ?Model $record): bool => ! RuoloCorrente::puoPubblicare()
                            && $value !== ($record?->status ?? 'draft'))
                        ->helperText(fn (): ?string => RuoloCorrente::puoPubblicare()
                            ? null
                            : 'Il tuo ruolo su questo sito non consente di pubblicare: salva come bozza, un redattore lo mettera\' online.'),
                ]),

                ...PageForm::tabSeoGeoAeo(),
            ]),
        ]);
    }

    /** Un importo in euro nel form, in centesimi nel database. */
    private static function importo(string $nome, string $etichetta): TextInput
    {
        return TextInput::make($nome)
            ->label($etichetta)
            ->prefix('€')
            ->inputMode('decimal')
            ->placeholder('39,90')
            ->formatStateUsing(fn (mixed $state): ?string => is_int($state) ? Euro::daCentesimi($state) : $state)
            ->dehydrateStateUsing(fn (mixed $state): ?int => Euro::inCentesimi($state))
            ->rule(fn (): Closure => function (string $attributo, mixed $valore, Closure $fail): void {
                if ($valore === null || $valore === '') {
                    return;
                }

                $centesimi = Euro::inCentesimi($valore);

                if ($centesimi === null) {
                    $fail('Scrivi un importo, per esempio 39,90.');

                    return;
                }

                // La colonna e' un unsignedInteger: sopra questo tetto la
                // riga non entrerebbe nel database e MariaDB risponderebbe
                // con un 500 al posto di un errore nel campo.
                if ($centesimi > Euro::MASSIMO_CENTESIMI) {
                    $fail('L\'importo e\' troppo alto.');
                }
            });
    }
}
