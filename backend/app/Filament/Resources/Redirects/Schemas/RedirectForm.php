<?php

namespace App\Filament\Resources\Redirects\Schemas;

use App\Models\Page;
use App\Models\Redirect;
use App\Support\PerSito;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class RedirectForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('da')
                ->label('Vecchio indirizzo')
                ->required()
                ->maxLength(500)
                ->helperText('Solo il percorso: /vecchia-pagina. Puoi anche incollare l\'indirizzo intero, viene ridotto al percorso.')
                ->dehydrateStateUsing(fn (?string $state): string => Redirect::normalizza($state))
                ->unique(ignoreRecord: true, modifyRuleUsing: PerSito::regolaUnica(...))
                ->rules([
                    // Uno spazio o un a capo rendono il .htaccess non valido,
                    // e Apache risponde 500 su TUTTO il sito, non solo su
                    // questo indirizzo.
                    'regex:/^[^\s]+$/',
                ])
                ->validationMessages([
                    'regex' => 'Niente spazi: usa il percorso come compare nella barra degli indirizzi.',
                    'unique' => 'C\'e gia un reindirizzamento da questo indirizzo.',
                ])
                // Una pagina pubblicata su quell'indirizzo vince comunque
                // sulla regola (il .htaccess controlla se il file esiste):
                // il redirect resterebbe li' senza scattare mai.
                ->hint(fn (?string $state): ?string => self::esisteLaPagina($state)
                    ? 'Attenzione: esiste gia una pagina qui, il redirect non scattera'
                    : null)
                ->hintColor('warning')
                ->live(onBlur: true),

            TextInput::make('a')
                ->label('Nuovo indirizzo')
                ->required()
                ->maxLength(1000)
                ->helperText('Un percorso di questo sito (/nuova-pagina/) oppure un indirizzo completo su un altro sito.')
                ->rules(['regex:/^(\/[^\s]*|https?:\/\/[^\s]+)$/'])
                ->different('da')
                // Un solo validationMessages: chiamarlo due volte sovrascrive
                // il primo, e il messaggio piu' utile sparirebbe in silenzio.
                ->validationMessages([
                    'regex' => 'Deve iniziare con / oppure con https://, e non contenere spazi.',
                    'different' => 'Un indirizzo non puo rimandare a se stesso.',
                ]),

            Radio::make('codice')
                ->label('Tipo')
                ->options(Redirect::CODICI)
                ->default(301)
                ->required()
                ->columnSpanFull()
                // Un 302 usato al posto di un 301 e' il modo piu' comune di
                // buttare via il posizionamento di una pagina: qui si legge
                // cosa significano, invece di scegliere fra due numeri.
                ->helperText('Nel dubbio, permanente: e quello che sposta il posizionamento sul nuovo indirizzo.'),

            Toggle::make('attivo')
                ->label('Attivo')
                ->default(true)
                ->helperText('Spento resta scritto qui ma non finisce nel sito.'),

            TextInput::make('nota')
                ->label('Nota')
                ->maxLength(300)
                ->columnSpanFull()
                ->helperText('Perche esiste questo redirect. Fra sei mesi sara l\'unica cosa che lo spiega.'),
        ])->columns(2);
    }

    private static function esisteLaPagina(?string $percorso): bool
    {
        $slug = trim(Redirect::normalizza($percorso), '/');

        return $slug !== '' && Page::where('slug', $slug)->exists();
    }
}
