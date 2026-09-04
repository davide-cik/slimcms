<?php

namespace App\Http\Resources\Concerns;

use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Forma JSON condivisa di un file media.
 *
 * Sta in un trait perche' pagine e articoli devono esporlo IDENTICO: se le
 * due forme divergessero, il frontend dovrebbe gestire due casi per la stessa
 * cosa, ed e' il tipo di differenza che si scopre tardi.
 */
trait ConMedia
{
    protected function mediaPubblico(?Media $media): ?array
    {
        if ($media === null) {
            return null;
        }

        return [
            'id' => $media->id,
            'url' => $media->hasGeneratedConversion('web') ? $media->getUrl('web') : $media->getUrl(),
            'url_originale' => $media->getUrl(),
            'anteprima' => $media->hasGeneratedConversion('anteprima') ? $media->getUrl('anteprima') : null,
            // L'alt e' una proprieta' del FILE, non della pagina che lo usa:
            // segue l'immagine ovunque venga riusata.
            'alt' => $media->getCustomProperty('alt'),
            'didascalia' => $media->getCustomProperty('didascalia'),
            'mime' => $media->mime_type,
            'size' => $media->size,
        ];
    }
}
