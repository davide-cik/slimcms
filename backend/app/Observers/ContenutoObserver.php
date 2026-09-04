<?php

namespace App\Observers;

use App\Models\Page;
use App\Models\Post;
use App\Models\Site;
use App\Services\BuildQueue;
use Illuminate\Database\Eloquent\Model;

/**
 * Accoda una rigenerazione quando un contenuto cambia.
 *
 * Osserva Page e Post. Non fa nulla per le bozze che restano bozze: una
 * pagina mai pubblicata non esiste nel sito statico, rigenerarlo sarebbe
 * lavoro sprecato a ogni salvataggio automatico dell'editor.
 */
class ContenutoObserver
{
    public function saved(Model $model): void
    {
        $eraPubblicato = $model->getOriginal('status') === 'published';
        $ePubblicato = $model->status === 'published';

        // Bozza che resta bozza: il sito pubblico non cambia.
        if (! $eraPubblicato && ! $ePubblicato) {
            return;
        }

        $this->accoda($model, $eraPubblicato && ! $ePubblicato ? 'content.unpublished' : 'content.saved');
    }

    public function deleted(Model $model): void
    {
        if ($model->getOriginal('status') !== 'published') {
            return;
        }

        $this->accoda($model, 'content.deleted');
    }

    public function restored(Model $model): void
    {
        $this->accoda($model, 'content.restored');
    }

    private function accoda(Model $model, string $reason): void
    {
        // Il sito NON si prende da app('currentSite'): in coda o in console
        // quel binding puo' non esserci, e la build finirebbe sul sito
        // sbagliato o su nessuno. Si prende dalla riga stessa.
        $site = Site::withoutTenancy()->find($model->site_id);

        if ($site === null) {
            return;
        }

        BuildQueue::accoda($site, $reason, 'incremental', [$this->percorso($model)]);
    }

    private function percorso(Model $model): string
    {
        if ($model instanceof Post) {
            return '/blog/' . $model->slug;
        }

        if ($model instanceof Page) {
            return $model->slug === 'home' ? '/' : '/' . $model->slug;
        }

        return '/';
    }
}
