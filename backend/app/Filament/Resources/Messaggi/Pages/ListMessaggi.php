<?php

namespace App\Filament\Resources\Messaggi\Pages;

use App\Filament\Resources\Messaggi\MessaggioResource;
use Filament\Resources\Pages\ListRecords;

class ListMessaggi extends ListRecords
{
    protected static string $resource = MessaggioResource::class;

    // Nessuna azione di intestazione: i messaggi arrivano, non si creano.
    protected function getHeaderActions(): array
    {
        return [];
    }
}
