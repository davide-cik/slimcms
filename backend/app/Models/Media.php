<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Spatie\MediaLibrary\MediaCollections\Models\Media as SpatieMedia;

/**
 * Modello Media proprio, che sostituisce quello di Spatie.
 *
 * PERCHE': la tabella media di Spatie e' solo polimorfa (model_type/model_id)
 * e non ha nessuna colonna di scoping. Cosi' com'e' sfuggirebbe al global
 * scope su cui poggia tutto l'isolamento del progetto, e il file caricato da
 * un cliente sarebbe elencabile da un altro. Con BelongsToSite e la colonna
 * site_id aggiunta alla migrazione, i media rientrano nella stessa garanzia
 * di Page e Post, e TenantScopeTest li copre automaticamente.
 *
 * Si registra in config/media-library.php -> 'media_model'.
 */
class Media extends SpatieMedia
{
    use BelongsToSite;

    /**
     * Spatie definisce $guarded = []; il trait ha comunque bisogno che
     * site_id sia scrivibile, quindi non lo restringiamo.
     */
    protected $guarded = [];
}
