<?php

namespace App\ControlPlane\Filament\Resources\Accessi\Pages;

use App\ControlPlane\Filament\Resources\Accessi\AccessoResource;
use Filament\Resources\Pages\ListRecords;

class ListAccessi extends ListRecords
{
    protected static string $resource = AccessoResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
