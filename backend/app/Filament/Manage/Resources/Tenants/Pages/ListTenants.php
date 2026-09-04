<?php

namespace App\Filament\Manage\Resources\Tenants\Pages;

use App\Filament\Manage\Resources\Tenants\TenantResource;
use Filament\Resources\Pages\ListRecords;

class ListTenants extends ListRecords
{
    protected static string $resource = TenantResource::class;
}
