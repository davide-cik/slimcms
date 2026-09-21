<?php

namespace App\Filament\Resources\Prodotti\Pages;

use App\Filament\Resources\Prodotti\ProdottoResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditProdotto extends EditRecord
{
    protected static string $resource = ProdottoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
