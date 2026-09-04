<?php

namespace App\ControlPlane\Filament\Resources\Tenants\Pages;

use App\ControlPlane\Filament\Resources\Tenants\TenantResource;
use Filament\Resources\Pages\EditRecord;

class EditTenant extends EditRecord
{
    protected static string $resource = TenantResource::class;
}
