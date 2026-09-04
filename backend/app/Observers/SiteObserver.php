<?php

namespace App\Observers;

use App\Models\Site;
use App\Services\BuildQueue;

/**
 * Un cambio strutturale (tema, nome, dominio) tocca ogni pagina del sito,
 * quindi richiede una build completa e non incrementale.
 */
class SiteObserver
{
    public function created(Site $site): void
    {
        // reason 'site.created' salta il debounce: primo deploy prioritario.
        BuildQueue::accoda($site, 'site.created', 'full');
    }

    public function updated(Site $site): void
    {
        $strutturali = ['domain', 'name', 'theme', 'logo_path', 'favicon_path', 'seo_defaults'];

        if (empty(array_intersect($strutturali, array_keys($site->getChanges())))) {
            return;
        }

        BuildQueue::accoda($site, 'site.updated', 'full');
    }
}
