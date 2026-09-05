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
        'favicon_initials',
        'og_config',
        'footer_config',
        'layout_config',
        'theme',
        'seo_defaults',
        'ssl_status',
        'ssl_expires_at',
        'ssl_checked_at',
        'ssl_last_error',
        'dns_status',
    ];

    protected function casts(): array
    {
        return [
            'theme' => 'array',
            'seo_defaults' => 'array',
            'og_config' => 'array',
            'footer_config' => 'array',
            'layout_config' => 'array',
            // Senza questi cast le colonne arrivano come stringhe e ogni
            // ->format() in tabella o nei form esplode con "Call to a member
            // function format() on string". Le colonne sono state aggiunte da
            // una migrazione successiva alla creazione del modello: e' il
            // punto in cui e' facile dimenticarsene.
            'ssl_expires_at' => 'datetime',
            'ssl_checked_at' => 'datetime',
        ];
    }

    /**
     * Il segmento sotto cui vive il blog: /blog/, /news/, /articoli/...
     *
     * Sta qui e non nel frontend perche' lo usano in tre posti che devono
     * concordare: le URL della sitemap, il JSON-LD e le rotte generate da
     * Astro. Tre copie della stessa stringa e' esattamente la giuntura che in
     * questo progetto ha gia' prodotto piu' di un guasto.
     */
    public function baseBlog(): string
    {
        $base = trim((string) ($this->layout_config['blog']['base'] ?? 'blog'), '/');

        // Un valore vuoto o assurdo metterebbe gli articoli sulla radice, dove
        // si scontrerebbero con gli slug delle pagine.
        return preg_match('/^[a-z0-9-]{1,40}$/', $base) === 1 ? $base : 'blog';
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
    /**
     * SVG della favicon del sito.
     *
     * Se e' stata caricata un'immagine vince quella; altrimenti si genera
     * dalle iniziali. Non c'e' un terzo caso "nessuna favicon": una scheda
     * senza icona e' peggio di una generata, e generarla non costa nulla.
     */
    public function faviconSvg(): string
    {
        return app(\App\Services\GeneratoreFavicon::class)->svg($this);
    }

    public function faviconIniziali(): string
    {
        return app(\App\Services\GeneratoreFavicon::class)->iniziali($this);
    }

    /** URL pubblica della favicon: il file caricato, o quella generata. */
    public function faviconUrl(): string
    {
        if (filled($this->favicon_path)) {
            return $this->favicon_path;
        }

        return '/favicon.svg';
    }

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
