<?php

namespace App\ControlPlane\Filament\Resources\Tenants;

use App\Models\Tenant;
use App\Support\Slug;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TenantResource extends Resource
{
    protected static ?string $model = Tenant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $modelLabel = 'cliente';

    protected static ?string $pluralModelLabel = 'clienti';

    protected static ?string $navigationLabel = 'Clienti';

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * Un operatore di assistenza vede solo i clienti che gli sono stati
     * assegnati; il super-admin li vede tutti. Senza questo, "supporto scoped"
     * sarebbe solo un'etichetta sul ruolo.
     */
    public static function getEloquentQuery(): Builder
    {
        $utente = auth('manage')->user();

        if ($utente === null || $utente->isSuperAdmin()) {
            return parent::getEloquentQuery();
        }

        return parent::getEloquentQuery()
            ->whereIn('id', $utente->tenants()->pluck('tenants.id'));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Ragione sociale')
                ->required()
                ->maxLength(180)
                ->live(onBlur: true)
                ->afterStateUpdated(function (?string $state, callable $set, string $operation) {
                    if ($operation === 'create') {
                        $set('slug', Slug::da($state));
                        $set('id', Slug::da($state));
                    }
                }),

            TextInput::make('slug')
                ->label('Slug')
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(120),

            TextInput::make('id')
                ->label('Identificativo')
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(120)
                // Cambiare l'id dopo la creazione significherebbe orfanare
                // ogni sito del cliente: la chiave e' una stringa, non un
                // autoincrement, e viene referenziata da sites.tenant_id.
                ->disabledOn('edit')
                ->helperText('Non modificabile dopo la creazione: i siti vi fanno riferimento.'),

            Select::make('status')
                ->label('Stato')
                ->options([
                    'trial' => 'In prova',
                    'active' => 'Attivo',
                    'suspended' => 'Sospeso',
                ])
                ->default('trial')
                ->required()
                ->helperText('Sospeso: i siti restano online ma il pannello non e accessibile.'),

            Select::make('plan_id')
                ->label('Piano')
                ->relationship('plan', 'name')
                ->preload(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Cliente')->searchable()->sortable()->weight('medium'),
                TextColumn::make('plan.name')->label('Piano')->badge()->placeholder('—')
                    ->visibleFrom('md'),

                TextColumn::make('sites_count')
                    ->label('Siti')
                    ->counts('sites')
                    ->badge()
                    // Un cliente che ha superato il limite del piano va visto
                    // subito: e' una conversazione commerciale, non un errore.
                    ->color(fn ($state, $record): string => $record->plan && $state > $record->plan->max_sites
                        ? 'danger'
                        : 'gray')
                    ->tooltip(fn ($record): ?string => $record->plan
                        ? "Massimo previsto dal piano: {$record->plan->max_sites}"
                        : null),

                TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'active' => 'Attivo',
                        'suspended' => 'Sospeso',
                        default => 'In prova',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'suspended' => 'danger',
                        default => 'warning',
                    }),

                TextColumn::make('created_at')->label('Cliente dal')->date('d/m/Y')->sortable()
                    ->visibleFrom('lg'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->label('Stato')->options([
                    'trial' => 'In prova', 'active' => 'Attivo', 'suspended' => 'Sospeso',
                ]),
            ])
            ->recordActions([EditAction::make()])
            // Nessuna azione di massa: cancellare clienti in blocco cancella a
            // cascata i loro siti e tutti i contenuti.
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTenants::route('/'),
            'create' => Pages\CreateTenant::route('/create'),
            'edit' => Pages\EditTenant::route('/{record}/edit'),
        ];
    }
}
