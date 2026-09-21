<?php

namespace App\Filament\Resources\Prodotti\Pages;

use App\Filament\Resources\Prodotti\ProdottoResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProdotti extends ListRecords
{
    protected static string $resource = ProdottoResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
