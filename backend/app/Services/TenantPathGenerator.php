<?php

namespace App\Services;

use App\Models\Site;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGenerator;

/**
 * Percorsi di storage isolati per cliente:
 *
 *   tenants/<tenant-id>/media/<media-id>/
 *
 * come previsto dalle specifiche (sezione 3). Il generatore di default di
 * Spatie usa solo l'id del media, quindi i file di clienti diversi finirebbero
 * mescolati nella stessa cartella: un backup selettivo, una cancellazione o
 * una migrazione per singolo cliente diventerebbero impossibili senza
 * interrogare il database file per file.
 *
 * Il tenant si ricava dal sito del media, non dal contesto della richiesta:
 * le conversioni delle immagini girano in coda, dove il contesto non c'e'.
 */
class TenantPathGenerator implements PathGenerator
{
    public function getPath(Media $media): string
    {
        return $this->prefisso($media) . $media->getKey() . '/';
    }

    public function getPathForConversions(Media $media): string
    {
        return $this->getPath($media) . 'conversions/';
    }

    public function getPathForResponsiveImages(Media $media): string
    {
        return $this->getPath($media) . 'responsive/';
    }

    private function prefisso(Media $media): string
    {
        $siteId = $media->site_id;

        if ($siteId === null) {
            // Non deve accadere: BelongsToSite assegna site_id alla creazione
            // e solleva eccezione se manca il contesto. Se succede comunque,
            // meglio una cartella dedicata che mescolare i file coi clienti.
            return 'orfani/media/';
        }

        // withoutTenancy: in coda la tenancy non e' inizializzata e il lookup
        // scoped non troverebbe il sito.
        $tenantId = Site::withoutTenancy()->whereKey($siteId)->value('tenant_id') ?? 'sconosciuto';

        return "tenants/{$tenantId}/media/";
    }
}
