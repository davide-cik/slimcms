<?php

namespace App\Support;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Validation\Rules\Unique;

/**
 * Regole di validazione ristrette al sito corrente.
 *
 * La regola `unique` di Laravel interroga la TABELLA, non il modello: il
 * global scope di BelongsToSite non la tocca. Senza il `where` esplicito, un
 * tag "novita" di un cliente impedirebbe a tutti gli altri di averne uno, e
 * un redirect /offerte su un sito bloccherebbe lo stesso percorso su ogni
 * altro sito della piattaforma.
 */
class PerSito
{
    /**
     * Il parametro si chiama `$rule` e non `$regola` perche' Filament inietta
     * le dipendenze delle closure PER NOME: con un nome diverso non trova
     * cosa passare e solleva un BindingResolutionException opaco.
     */
    public static function regolaUnica(Unique $rule): Unique
    {
        return $rule->where('site_id', BelongsToSite::currentSiteId());
    }
}
