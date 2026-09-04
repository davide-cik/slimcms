<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

/**
 * Modello del control plane: un cliente della piattaforma.
 * NON e' scoped (vive a livello di piattaforma, non di singolo sito).
 */
class Tenant extends BaseTenant implements TenantWithDatabase
{
    // NOTA: niente HasDomains e nessuna tabella "domains".
    // La mappa dominio -> sito e' la colonna sites.domain, che e' l'unica
    // fonte di verita'. La tabella domains di stancl mappa dominio -> TENANT,
    // un livello troppo grossolano: un tenant puo' avere piu' siti con domini
    // diversi. Due fonti di verita' sullo stesso dato produrrebbero
    // risoluzioni sbagliate silenziose.

    use HasDatabase;

    public static function getCustomColumns(): array
    {
        return [
            'id',
            'name',
            'slug',
            'status',
            'plan_id',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }
}
