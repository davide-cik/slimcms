<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * Un mini sito gestito dalla piattaforma.
 *
 * PRIMO livello di isolamento: Site e' scoped per tenant_id tramite il
 * trait di stancl, quindi nessuna query puo' leggere il sito di un altro
 * cliente quando il contesto tenant e' inizializzato. I contenuti del
 * sito (Page, Post, Media) sono a loro volta scoped per site_id: due
 * barriere indipendenti, difesa in profondita'.
 */
class Site extends Model implements HasMedia
{
    use BelongsToTenant;
    use HasFactory;
    use InteractsWithMedia;

    protected $fillable = [
        'tenant_id',
        'domain',
        'name',
        'logo_path',
        'favicon_path',
        'theme',
        'seo_defaults',
    ];

    protected function casts(): array
    {
        return [
            'theme' => 'array',
            'seo_defaults' => 'array',
        ];
    }

    /**
     * Nelle URL il sito e' identificato dal dominio, non dall'id numerico:
     * /api/sites/slimcms.it/pages e' leggibile e stabile, /api/sites/1/pages no.
     */
    public function getRouteKeyName(): string
    {
        return 'domain';
    }

    /**
     * La libreria media appartiene al SITO, non alla singola pagina: un
     * redattore carica una volta e riusa il file dove serve. E' anche il
     * motivo per cui i file vivono sotto tenants/<id>/media/ e non sotto
     * la pagina che per prima li ha usati.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('libreria')
            ->useDisk(config('media-library.disk_name'));
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        // Anteprima per la griglia del pannello: senza, il browser
        // scaricherebbe l'originale a piena risoluzione per ogni miniatura.
        $this->addMediaConversion('anteprima')
            ->fit(Fit::Contain, 320, 320)
            ->nonQueued();

        // Formato per il web, usato dalle pagine pubbliche.
        $this->addMediaConversion('web')
            ->fit(Fit::Max, 1600, 1600)
            ->nonQueued();
    }

    /** I redattori assegnati a questo sito, col ruolo sul pivot. */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    public function pages(): HasMany
    {
        return $this->hasMany(Page::class);
    }

    /**
     * Imposta questo sito come sito corrente nel container.
     *
     * Da chiamare esplicitamente in job, comandi artisan e seeder prima di
     * toccare modelli che usano BelongsToSite: in quei contesti non c'e'
     * nessun middleware che risolva il sito dal dominio, e senza questo
     * la creazione di un record scoped fallisce di proposito.
     */
    public function useAsCurrent(): static
    {
        app()->instance('currentSite', $this);

        return $this;
    }

    /**
     * Ripulisce il sito corrente dal container. Utile fra un tenant e
     * l'altro in un comando che cicla su piu' siti.
     */
    public static function forgetCurrent(): void
    {
        app()->forgetInstance('currentSite');
    }
}
