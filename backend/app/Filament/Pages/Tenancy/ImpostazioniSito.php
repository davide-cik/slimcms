<?php

namespace App\Filament\Pages\Tenancy;

use App\Models\Site;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
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
 * Stavano nel control plane, insieme al dominio e al piano. Il dominio e il
 * piano sono affari della piattaforma; la favicon e' il sito. Chiedere a noi
 * di cambiare l'icona del proprio sito e' come chiedere all'idraulico di
 * cambiare il campanello: chi lo abita deve poterlo fare da solo.
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
                        ->acceptedFileTypes(['image/svg+xml', 'image/png', 'image/x-icon'])
                        ->directory('favicon')
                        ->visible(fn (Get $get) => $get('favicon_modo') === 'caricata')
                        ->helperText('SVG, PNG o ICO, massimo 512 KB. Consigliato quadrato, almeno 128x128. '
                            . 'Il file resta qui: il sito pubblica una copia in /favicon.ico, generata da questa immagine.')
                        // Passando a "generata" il file va tolto, altrimenti
                        // resterebbe e continuerebbe ad avere la precedenza.
                        ->dehydrateStateUsing(fn ($state, Get $get) => $get('favicon_modo') === 'caricata' ? $state : null),
                ])->columns(2),
        ]);
    }
}
