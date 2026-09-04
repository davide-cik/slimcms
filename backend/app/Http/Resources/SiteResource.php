<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SiteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'domain' => $this->domain,
            'name' => $this->name,
            'logo_path' => $this->logo_path,
            'favicon_path' => $this->favicon_path,
            'theme' => $this->theme ?? [],
            'seo_defaults' => $this->seo_defaults ?? [],
        ];
    }
}
