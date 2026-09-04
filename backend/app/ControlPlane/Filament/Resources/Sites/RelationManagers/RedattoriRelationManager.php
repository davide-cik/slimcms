<?php

namespace App\ControlPlane\Filament\Resources\Sites\RelationManagers;

use App\Models\User;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;

/**
 * Assegna i redattori a un sito, dal control plane.
 *
 * E' l'operazione che fa partire davvero un cliente: un sito senza redattori
 * non e' amministrabile da nessuno.
 */
class RedattoriRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    protected static ?string $title = 'Redattori';

    protected static ?string $modelLabel = 'redattore';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('role')
                ->label('Ruolo su questo sito')
                ->options([
                    'admin' => 'Amministratore — gestisce anche gli altri redattori',
                    'editor' => 'Redattore — crea e pubblica contenuti',
                    'author' => 'Autore — crea contenuti, non pubblica',
                    'viewer' => 'In sola lettura',
                ])
                ->default('editor')
                ->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Nome')->weight('medium'),
                TextColumn::make('email')->label('Email')->copyable()->color('gray'),
                TextColumn::make('role')
                    ->label('Ruolo')
                    ->badge()
                    ->state(fn (User $record): string => $record->pivot->role ?? 'editor')
                    ->color(fn (string $state): string => $state === 'admin' ? 'success' : 'gray'),
                TextColumn::make('altri_siti')
                    ->visibleFrom('lg')
                    ->label('Altri siti')
                    ->state(fn (User $record): int => $record->sites()->withoutTenancy()
                        ->whereKeyNot($this->getOwnerRecord())->count())
                    ->color('gray'),
            ])
            ->headerActions([
                // Aggancia un redattore che gia' esiste.
                AttachAction::make()
                    ->label('Aggiungi un redattore esistente')
                    ->preloadRecordSelect()
                    ->recordSelectSearchColumns(['name', 'email'])
                    ->recordSelectOptionsQuery(function (Builder $query) {
                        // Un utente appartiene a UN cliente solo (specifiche,
                        // sezione 5): mostrare i redattori di altri clienti
                        // renderebbe possibile assegnarli qui per sbaglio.
                        $tenantId = $this->getOwnerRecord()->tenant_id;

                        return $query->withoutSitePivotScope()
                            ->where(fn (Builder $q) => $q
                                ->whereDoesntHave('sites')
                                ->orWhereHas('sites', fn (Builder $s) => $s
                                    ->withoutTenancy()
                                    ->where('sites.tenant_id', $tenantId)));
                    })
                    ->schema(fn (AttachAction $action): array => [
                        $action->getRecordSelect(),
                        Select::make('role')
                            ->label('Ruolo')
                            ->options([
                                'admin' => 'Amministratore',
                                'editor' => 'Redattore',
                                'author' => 'Autore',
                                'viewer' => 'In sola lettura',
                            ])
                            ->default('editor')
                            ->required(),
                    ]),

                // Crea un redattore nuovo e lo aggancia in un colpo solo: e'
                // il caso normale quando parte un cliente.
                \Filament\Actions\Action::make('nuovo')
                    ->label('Crea un nuovo redattore')
                    ->icon('heroicon-o-user-plus')
                    ->schema([
                        TextInput::make('name')->label('Nome')->required()->maxLength(190),
                        TextInput::make('email')->label('Email')->email()->required()
                            ->unique(table: 'users', column: 'email'),
                        TextInput::make('password')->label('Password')->password()->revealable()
                            ->required()->minLength(8)
                            ->helperText('Comunicala al cliente su un canale sicuro. Potra cambiarla dal proprio profilo.'),
                        Select::make('role')->label('Ruolo')->options([
                            'admin' => 'Amministratore',
                            'editor' => 'Redattore',
                            'author' => 'Autore',
                            'viewer' => 'In sola lettura',
                        ])->default('admin')->required()
                            ->helperText('Il primo redattore di un sito di solito e amministratore.'),
                    ])
                    ->action(function (array $data): void {
                        $utente = User::withoutSitePivotScope()->create([
                            'name' => $data['name'],
                            'email' => $data['email'],
                            'password' => Hash::make($data['password']),
                        ]);

                        $this->getOwnerRecord()->users()
                            ->syncWithoutDetaching([$utente->id => ['role' => $data['role']]]);
                    }),
            ])
            ->recordActions([
                DetachAction::make()
                    ->label('Rimuovi')
                    ->modalHeading('Rimuovere il redattore da questo sito?')
                    // Staccare non cancella: la persona puo' lavorare su altri
                    // siti dello stesso cliente.
                    ->modalDescription('Il suo account resta attivo sugli altri siti a cui ha accesso.'),
            ])
            ->emptyStateHeading('Nessun redattore')
            ->emptyStateDescription('Finche non ne assegni almeno uno, nessuno puo amministrare questo sito.');
    }
}
