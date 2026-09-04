<?php

namespace App\ControlPlane\Filament\Resources\Sites\Pages;

use App\ControlPlane\Filament\Resources\Sites\SiteResource;
use Filament\Resources\Pages\ListRecords;

class ListSites extends ListRecords
{
    protected static string $resource = SiteResource::class;
}
