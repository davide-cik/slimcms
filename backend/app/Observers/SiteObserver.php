<?php

namespace App\Observers;

use App\Models\Site;
use App\Services\BuildQueue;
use Illuminate\Support\Facades\Artisan;

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
        $this->rigeneraMappa();
    }

    public function deleted(Site $site): void
    {
        // Un dominio rimosso deve sparire dalla mappa subito, altrimenti
        // l'edge continua a puntare a una cartella che non esiste piu'.
        $this->rigeneraMappa();
    }

    public function updated(Site $site): void
    {
        $strutturali = ['domain', 'name', 'theme', 'logo_path', 'favicon_path', 'seo_defaults'];

        if (empty(array_intersect($strutturali, array_keys($site->getChanges())))) {
            return;
        }

        BuildQueue::accoda($site, 'site.updated', 'full');

        // La mappa si rigenera SOLO se e' cambiato il dominio: e' il punto
        // della sezione 7.2, che la risoluzione non stia nel percorso di
        // lettura e la mappa cambi solo su eventi strutturali.
        if (array_key_exists('domain', $site->getChanges())) {
            $this->rigeneraMappa();
        }
    }

    private function rigeneraMappa(): void
    {
        Artisan::call('slimcms:mappa-routing');
    }
}
