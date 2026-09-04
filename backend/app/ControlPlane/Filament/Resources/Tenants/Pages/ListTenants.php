<?php

namespace App\ControlPlane\Filament\Resources\Tenants\Pages;

use App\ControlPlane\Filament\Resources\Tenants\TenantResource;
use Filament\Resources\Pages\ListRecords;

class ListTenants extends ListRecords
{
    protected static string $resource = TenantResource::class;
}
