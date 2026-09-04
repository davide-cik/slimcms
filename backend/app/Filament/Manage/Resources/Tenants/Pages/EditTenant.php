<?php

namespace App\Filament\Manage\Resources\Tenants\Pages;

use App\Filament\Manage\Resources\Tenants\TenantResource;
use Filament\Resources\Pages\EditRecord;

class EditTenant extends EditRecord
{
    protected static string $resource = TenantResource::class;
}
