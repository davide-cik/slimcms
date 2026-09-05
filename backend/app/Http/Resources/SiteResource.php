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
            // `favicon_path` non esce piu': e' il percorso su un disco
            // PRIVATO del backend, non un indirizzo. Usato com'era, come href
            // nell'HTML del sito, dava un 404 garantito. Chi ha caricato un
            // file lo ritrova dentro /favicon.ico, che il backend genera da
            // quella stessa immagine.
            //
            // L'SVG viaggia inline: e' poche centinaia di byte e cosi' Astro
            // lo scrive come file del sito senza una richiesta in piu'. E'
            // null quando il cliente ha caricato un'immagine non vettoriale,
            // perche' un PNG non diventa un SVG e il sito in quel caso
            // dichiara solo l'ICO.
            'favicon_svg' => app(\App\Services\GeneratoreFavicon::class)->svgPubblicabile($this->resource),
            'favicon_iniziali' => $this->faviconIniziali(),
            'theme' => $this->theme ?? [],
            'seo_defaults' => $this->seo_defaults ?? [],
            'og_config' => $this->og_config ?? [],
            'footer_config' => $this->footer_config ?? [],
            'layout_config' => $this->layout_config ?? [],
            // Normalizzato qui: Astro non deve ripetere la stessa validazione
            // per poi divergere alla prima modifica.
            'base_blog' => $this->baseBlog(),
        ];
    }
}
