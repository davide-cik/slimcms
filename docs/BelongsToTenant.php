<?php

namespace App\Models\Concerns;

use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Trait BelongsToTenant
 *
 * Da usare su OGNI modello che contiene dati appartenenti a un singolo
 * tenant/sito (es. Page, Post, Media). Applica automaticamente un
 * global scope che filtra ogni query per il tenant/sito corrente,
 * cosi' nessuno sviluppatore deve ricordarsi di farlo manualmente
 * a ogni nuova query o a ogni nuovo modello.
 *
 * Il modello che usa questo trait deve avere una colonna "site_id"
 * (o "tenant_id", personalizzabile tramite getTenantForeignKey()).
 *
 * USO:
 *   class Page extends Model
 *   {
 *       use BelongsToTenant;
 *   }
 */
trait BelongsToTenant
{
    /**
     * Boot del trait: registra il global scope e l'auto-assegnazione
     * del tenant corrente alla creazione di un nuovo record.
     */
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            $tenantId = static::currentTenantId();

            // In contesto console (seed, migration, comandi artisan senza
            // tenant risolto) lo scope non va applicato, altrimenti nulla
            // sarebbe mai leggibile. Va sempre esplicitato un tenant quando
            // si opera in questi contesti (es. tramite tenancy()->initialize()).
            if ($tenantId !== null) {
                $builder->where(
                    $builder->getModel()->getTable() . '.' . static::getTenantForeignKeyStatic(),
                    $tenantId
                );
            }
        });

        static::creating(function (Model $model) {
            $foreignKey = $model->getTenantForeignKey();

            if (empty($model->{$foreignKey})) {
                $model->{$foreignKey} = static::currentTenantId();
            }
        });
    }

    /**
     * Nome della colonna usata per lo scoping. Sovrascrivere nel modello
     * se la colonna non si chiama "site_id" (es. "tenant_id").
     */
    public function getTenantForeignKey(): string
    {
        return property_exists($this, 'tenantForeignKey')
            ? $this->tenantForeignKey
            : 'site_id';
    }

    protected static function getTenantForeignKeyStatic(): string
    {
        return (new static())->getTenantForeignKey();
    }

    /**
     * Risolve il tenant/sito corrente dal contesto applicativo.
     *
     * Adattare questo metodo al meccanismo di risoluzione tenant scelto:
     * - se si usa stancl/tenancy: tenancy()->tenant?->id
     * - se si usa un binding custom nel middleware: app(Site::class)->id
     *   oppure app()->bound('currentSite') ? app('currentSite')->id : null
     *
     * Qui sotto un esempio con binding custom nel container, popolato
     * da un middleware che risolve il sito dal dominio della richiesta.
     */
    public static function currentTenantId(): ?int
    {
        if (app()->bound('currentSite')) {
            /** @var Site $site */
            $site = app('currentSite');

            return $site?->id;
        }

        return null;
    }

    /**
     * Scope locale per bypassare esplicitamente il tenant scope quando
     * serve davvero (es. job in coda, comandi artisan cross-tenant),
     * da usare sempre con consapevolezza e mai come scorciatoia di comodo.
     *
     * Uso: Page::withoutTenantScope()->get();
     */
    public function scopeWithoutTenantScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope('tenant');
    }
}
