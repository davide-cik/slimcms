<?php

namespace App\Filament\Resources\Moduli\Schemas;

use App\Models\Modulo;
use App\Support\PerSito;
use App\Support\Slug;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ModuloForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Il modulo')->schema([
                TextInput::make('nome')
                    ->label('Nome')
                    ->required()
                    ->maxLength(120)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (?string $state, callable $set) => $set('slug', Slug::da($state)))
                    ->helperText('Come lo chiami tu. Il visitatore non lo vede.'),

                TextInput::make('slug')
                    ->label('Sigla')
                    ->required()
                    ->maxLength(120)
                    // Viaggia nell'invio dal sito: se cambia, i moduli gia'
                    // pubblicati smettono di essere riconosciuti fino alla
                    // build successiva.
                    ->unique(table: 'moduli', ignoreRecord: true, modifyRuleUsing: PerSito::regolaUnica(...))
                    ->helperText('Identifica il modulo negli invii. Cambiala solo se serve davvero.'),

                TextInput::make('email_destinatario')
                    ->label('Email del destinatario')
                    ->email()
                    ->maxLength(180)
                    ->placeholder('quella del sito')
                    ->helperText('Lascia vuoto per usare l\'indirizzo impostato nelle impostazioni del sito.'),

                TextInput::make('messaggio_conferma')
                    ->label('Messaggio di conferma')
                    ->maxLength(200)
                    ->placeholder('Messaggio ricevuto. Ti rispondiamo al piu\' presto.')
                    ->helperText('Quello che il visitatore legge dopo l\'invio.'),

                Toggle::make('attivo')
                    ->label('Attivo')
                    ->default(true)
                    ->helperText('Un modulo spento non accetta piu\' invii, ma i messaggi ricevuti restano.'),
            ])->columns(2),

            Section::make('Campi in piu\'')
                ->description('Nome, email e messaggio ci sono sempre. Qui si aggiunge il resto.')
                ->schema([
                    Repeater::make('campi')
                        ->hiddenLabel()
                        ->addActionLabel('Aggiungi un campo')
                        ->reorderable()
                        ->collapsible()
                        ->itemLabel(fn (array $state): ?string => $state['etichetta'] ?? null)
                        ->schema([
                            TextInput::make('etichetta')
                                ->label('Etichetta')
                                ->required()
                                ->maxLength(80)
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn (?string $state, callable $set) => $set(
                                    'nome',
                                    str_replace('-', '_', Slug::da($state))
                                ))
                                ->helperText('Quello che legge il visitatore.'),

                            TextInput::make('nome')
                                ->label('Sigla')
                                ->required()
                                ->maxLength(40)
                                ->helperText('Come arriva nei messaggi.'),

                            Select::make('tipo')
                                ->label('Tipo')
                                ->options(Modulo::TIPI)
                                ->default('testo')
                                ->required()
                                ->live(),

                            Toggle::make('obbligatorio')->label('Obbligatorio'),

                            Repeater::make('opzioni')
                                ->label('Opzioni')
                                ->simple(TextInput::make('valore')->required()->maxLength(120))
                                ->visible(fn (Get $get): bool => $get('tipo') === 'scelta')
                                ->columnSpanFull()
                                ->addActionLabel('Aggiungi un\'opzione'),
                        ])->columns(2),
                ]),
        ]);
    }
}
