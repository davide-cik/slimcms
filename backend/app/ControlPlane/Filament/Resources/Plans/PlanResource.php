<?php

namespace App\ControlPlane\Filament\Resources\Plans;

use App\Models\Plan;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PlanResource extends Resource
{
    protected static ?string $model = Plan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyEuro;

    protected static ?string $modelLabel = 'piano';

    protected static ?string $pluralModelLabel = 'piani';

    protected static ?string $navigationLabel = 'Piani';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Nome')->required()->maxLength(120),

            TextInput::make('price_monthly')
                ->label('Prezzo mensile')
                ->numeric()
                ->prefix('€')
                ->default(0)
                ->required(),

            TextInput::make('max_sites')
                ->label('Siti inclusi')
                ->numeric()
                ->minValue(1)
                ->default(1)
                ->required(),

            TextInput::make('max_storage_gb')
                ->label('Spazio incluso')
                ->numeric()
                ->minValue(1)
                ->suffix('GB')
                ->default(1)
                ->required(),

            CheckboxList::make('features_included')
                ->label('Funzioni incluse')
                ->options([
                    'seo' => 'Campi SEO',
                    'geo' => 'Campi GEO (motori generativi)',
                    'aeo' => 'Campi AEO (risposte dirette e FAQ)',
                    'blog' => 'Blog',
                    'form' => 'Form di contatto',
                    'custom_domain' => 'Dominio personalizzato',
                ])
                ->columns(2)
                ->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Piano')->searchable()->sortable()->weight('medium'),
                TextColumn::make('price_monthly')->label('Al mese')->money('EUR')->sortable(),
                TextColumn::make('max_sites')->label('Siti')->badge(),
                TextColumn::make('max_storage_gb')->label('Spazio')->suffix(' GB'),
                TextColumn::make('tenants_count')->label('Clienti')->counts('tenants')->badge()->color('success'),
            ])
            ->defaultSort('price_monthly')
            ->recordActions([EditAction::make()])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPlans::route('/'),
            'create' => Pages\CreatePlan::route('/create'),
            'edit' => Pages\EditPlan::route('/{record}/edit'),
        ];
    }
}
