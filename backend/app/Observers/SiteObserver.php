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
        // L'elenco e' di cio' che NON tocca il sito pubblicato, non di cio'
        // che lo tocca. Nell'altro verso era un elenco da aggiornare a ogni
        // colonna nuova, e non e' successo: `footer_config`, `layout_config`,
        // `og_config` e `favicon_initials` sono arrivate dopo e non ci sono
        // mai entrate. Cambiare il footer o la testata dal pannello non
        // accodava nessuna build, e il sito restava com'era senza dirlo.
        //
        // Cosi' invece una colonna nuova accoda una build finche' qualcuno
        // non dichiara il contrario: una build di troppo si nota e costa un
        // minuto, una build che non parte non si nota affatto.
        $operative = [
            'id', 'tenant_id', 'created_at', 'updated_at',
            // Stato di dominio e certificato: lo scrive il monitoraggio ogni
            // giorno, e non cambia una riga di quello che viene pubblicato.
            'ssl_status', 'ssl_expires_at', 'ssl_checked_at', 'ssl_last_error', 'dns_status',
        ];

        if (empty(array_diff(array_keys($site->getChanges()), $operative))) {
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
