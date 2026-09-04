<?php

namespace App\Filament\Manage\Resources\Tenants\Pages;

use App\Filament\Manage\Resources\Tenants\TenantResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTenant extends CreateRecord
{
    protected static string $resource = TenantResource::class;
}
