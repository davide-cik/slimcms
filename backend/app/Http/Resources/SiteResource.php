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
            // L'SVG viaggia inline: e' poche centinaia di byte e cosi' Astro
            // lo scrive come file del sito senza una richiesta in piu'.
            'favicon_svg' => $this->faviconSvg(),
            'favicon_iniziali' => $this->faviconIniziali(),
            'theme' => $this->theme ?? [],
            'seo_defaults' => $this->seo_defaults ?? [],
            'og_config' => $this->og_config ?? [],
        ];
    }
}
