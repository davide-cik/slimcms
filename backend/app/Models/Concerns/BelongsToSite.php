<?php

namespace App\Models\Concerns;

use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trait BelongsToSite
 *
 * Da usare su OGNI modello che contiene dati appartenenti a un singolo
 * mini sito (Page, Post, Media, ...). Applica automaticamente un global
 * scope che filtra ogni query per il sito corrente, cosi' nessuno
 * sviluppatore deve ricordarsene a ogni nuova query o nuovo modello.
 *
 * Questo e' il secondo dei due livelli di isolamento del progetto:
 *
 *   tenant_id -> Stancl\Tenancy\Database\Concerns\BelongsToTenant  (Site)
 *   site_id   -> App\Models\Concerns\BelongsToSite                 (Page, Post, Media)
 *
 * Il trait di stancl usa una proprieta' STATICA $tenantIdColumn condivisa
 * da tutti i modelli, quindi gestisce un solo nome di colonna a livello
 * globale: non puo' coprire anche il livello site_id. Da qui la necessita'
 * di questo trait separato, con un basename diverso per evitare la
 * collisione sul metodo boot* fra i due.
 *
 * USO:
 *   class Page extends Model
 *   {
 *       use BelongsToSite;
 *   }
 */
trait BelongsToSite
{
    /**
     * Nome della colonna usata per lo scoping.
     */
    public static string $siteIdColumn = 'site_id';

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, static::$siteIdColumn);
    }

    /**
     * Boot del trait: registra il global scope e l'auto-assegnazione
     * del sito corrente alla creazione di un nuovo record.
     */
    public static function bootBelongsToSite(): void
    {
        static::addGlobalScope('site', function (Builder $builder) {
            $siteId = static::currentSiteId();

            // In contesto console (seed, migration, comandi artisan senza
            // sito risolto) lo scope non va applicato, altrimenti nulla
            // sarebbe mai leggibile. Va sempre esplicitato un sito quando
            // si opera in questi contesti: vedi Site::useAsCurrent().
            if ($siteId !== null) {
                $builder->where(
                    $builder->getModel()->qualifyColumn(static::$siteIdColumn),
                    $siteId
                );
            }
        });

        static::creating(function (Model $model) {
            $column = static::$siteIdColumn;

            if (empty($model->{$column})) {
                $siteId = static::currentSiteId();

                // Fallire rumorosamente invece di inserire site_id = NULL:
                // una riga orfana e' invisibile a ogni query scoped e non
                // verrebbe notata fino a quando non e' troppo tardi.
                if ($siteId === null) {
                    throw new \RuntimeException(sprintf(
                        'Impossibile creare %s: nessun sito corrente nel contesto. '
                        . 'In job, comandi artisan e seeder inizializza esplicitamente '
                        . 'il sito con Site::useAsCurrent($site), oppure valorizza %s a mano.',
                        static::class,
                        $column
                    ));
                }

                $model->{$column} = $siteId;
            }
        });
    }

    /**
     * Risolve il sito corrente dal container. Il binding "currentSite" e'
     * popolato dal middleware che risolve il sito dal dominio della
     * richiesta, o esplicitamente via Site::useAsCurrent() in console.
     */
    public static function currentSiteId(): ?int
    {
        if (! app()->bound('currentSite')) {
            return null;
        }

        /** @var Site|null $site */
        $site = app('currentSite');

        return $site?->id;
    }

    /**
     * Scope locale per bypassare esplicitamente il site scope quando serve
     * davvero (es. job cross-site, comandi artisan di manutenzione), da
     * usare sempre con consapevolezza e mai come scorciatoia di comodo.
     *
     * Uso: Page::withoutSiteScope()->get();
     */
    public function scopeWithoutSiteScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope('site');
    }
}
