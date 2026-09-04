<?php

namespace App\ControlPlane\Filament\Resources\Plans\Pages;

use App\ControlPlane\Filament\Resources\Plans\PlanResource;
use Filament\Resources\Pages\ListRecords;

class ListPlans extends ListRecords
{
    protected static string $resource = PlanResource::class;
}
