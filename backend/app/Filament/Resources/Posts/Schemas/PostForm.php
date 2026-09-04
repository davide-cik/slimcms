<?php

namespace App\Filament\Resources\Posts\Schemas;

use App\Filament\Resources\Pages\Schemas\PageForm;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * Form di redazione di un articolo.
 *
 * I tab SEO / GEO / AEO sono gli stessi di PageForm e vengono riusati da li':
 * duplicarli avrebbe significato che un campo aggiunto in un posto sparisce
 * nell'altro, ed e' esattamente il tipo di divergenza che nessuno nota
 * finche' non manca un dato in produzione.
 */
class PostForm
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

                    TextInput::make('slug')->label('Slug')->required()->maxLength(255),

                    Textarea::make('excerpt')
                        ->label('Estratto')
                        ->rows(3)
                        ->maxLength(400)
                        ->helperText('Compare negli elenchi e nelle anteprime. Se vuoto, viene usata la meta description.'),

                    PageForm::blocchiPubblici(),
                ])->columns(1),

                Tabs\Tab::make('Classificazione')->schema([
                    Select::make('categories')
                        ->label('Categorie')
                        ->relationship('categories', 'name')
                        ->multiple()
                        ->preload()
                        ->createOptionForm([
                            TextInput::make('name')->label('Nome')->required(),
                            TextInput::make('slug')->label('Slug')->required(),
                        ]),

                    TagsInput::make('tags')
                        ->label('Tag')
                        ->helperText('Invio per confermare ogni tag.'),

                    Select::make('author_id')
                        ->label('Autore')
                        ->relationship('author', 'name')
                        ->searchable()
                        ->preload()
                        ->helperText('Solo i redattori di questo sito.'),

                    TextInput::make('featured_image_path')
                        ->label('Immagine di copertina')
                        ->helperText('Percorso del file. Diventera un selettore quando ci sara la media library.'),
                ])->columns(2),

                Tabs\Tab::make('Pubblicazione')->schema([
                    Select::make('status')
                        ->label('Stato')
                        ->options([
                            'draft' => 'Bozza',
                            'published' => 'Pubblicato',
                            'scheduled' => 'Programmato',
                        ])
                        ->default('draft')
                        ->required()
                        ->live(),

                    DateTimePicker::make('publish_at')
                        ->label('Data di pubblicazione')
                        ->seconds(false)
                        ->visible(fn (callable $get) => in_array($get('status'), ['scheduled', 'published'], true))
                        ->requiredIf('status', 'scheduled'),
                ])->columns(2),

                ...PageForm::tabSeoGeoAeo(),
            ]),
        ]);
    }
}
