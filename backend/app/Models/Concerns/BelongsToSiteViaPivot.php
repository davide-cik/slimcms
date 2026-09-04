<?php

namespace App\Models\Concerns;

use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;

/**
 * Trait BelongsToSiteViaPivot
 *
 * Per i modelli collegati a un sito tramite tabella PIVOT invece che con
 * una colonna site_id: oggi solo User, che e' many-to-many con Site perche'
 * la stessa persona puo' lavorare su piu' mini siti dello stesso cliente.
 *
 * BelongsToSite non puo' coprire questo caso: filtra su una colonna della
 * tabella, che qui non esiste. Senza questo scope la tabella users e'
 * GLOBALE, e una risorsa Filament sugli utenti mostrerebbe a ogni cliente
 * i redattori di tutti gli altri clienti.
 *
 * Attenzione: questo scope si basa sul tenant Filament, non sul binding
 * 'currentSite', perche' serve dentro il pannello. Fuori dal pannello
 * (API, job) e' inerte: li' il confine lo mettono le query esplicite.
 */
trait BelongsToSiteViaPivot
{
    public static function bootBelongsToSiteViaPivot(): void
    {
        static::addGlobalScope('siteViaPivot', function (Builder $builder) {
            if (! class_exists(Filament::class)) {
                return;
            }

            $site = Filament::getTenant();

            if ($site === null) {
                return;
            }

            $builder->whereHas('sites', fn (Builder $q) => $q->withoutTenancy()->whereKey($site->getKey()));
        });
    }

    public function scopeWithoutSitePivotScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope('siteViaPivot');
    }
}
