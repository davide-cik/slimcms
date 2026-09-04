<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Pagina di contenuto di un mini sito.
 *
 * SECONDO livello di isolamento: scoped per site_id.
 */
class Page extends Model implements HasMedia
{
    use BelongsToSite;
    use HasFactory;
    use InteractsWithMedia;
    use SoftDeletes;

    protected $fillable = [
        'site_id',
        'title',
        'slug',
        'is_home',
        'colonne',
        'blocks',
        'seo',
        'status',
        'publish_at',
    ];

    /**
     * Una sola home per sito, sempre.
     *
     * Promuovere una pagina a home degrada l'altra: senza questo si
     * finirebbe con due pagine che si contendono la radice, e quale delle due
     * vinca dipenderebbe dall'ordine con cui il frontend le legge.
     */
    public static function booted(): void
    {
        // Un sito senza pagina iniziale risponde 404 sulla propria radice e
        // la build non produce nemmeno un index.html: e' successo davvero.
        // La prima pagina di un sito diventa la home da sola, cosi' la regola
        // "un sito ha sempre una home" vale per costruzione e non per
        // disciplina di chi scrive i seeder.
        static::creating(function (self $pagina): void {
            if ($pagina->is_home) {
                return;
            }

            $esiste = static::withoutSiteScope()
                ->where('site_id', $pagina->site_id)
                ->where('is_home', true)
                ->exists();

            if (! $esiste) {
                $pagina->is_home = true;
            }
        });

        static::saved(function (self $pagina): void {
            if (! $pagina->is_home) {
                return;
            }

            static::withoutSiteScope()
                ->where('site_id', $pagina->site_id)
                ->whereKeyNot($pagina->getKey())
                ->where('is_home', true)
                ->each(fn (self $altra) => $altra->forceFill(['is_home' => false])->saveQuietly());
        });

        // La home non si cancella: un sito senza pagina iniziale risponde 404
        // sulla propria radice, ed e' un guasto che il redattore scopre dal
        // cliente invece che dal pannello.
        static::deleting(function (self $pagina): bool {
            if ($pagina->is_home) {
                throw new \RuntimeException(
                    'La pagina iniziale non si puo\' cancellare. Prima assegna il ruolo a un\'altra pagina.'
                );
            }

            return true;
        });
    }

    /** La pagina iniziale del sito corrente. */
    public static function home(): ?self
    {
        return static::where('is_home', true)->first();
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('immagini')
            ->useDisk(config('media-library.disk_name'));
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('anteprima')->fit(Fit::Contain, 320, 320)->nonQueued();
        $this->addMediaConversion('web')->fit(Fit::Max, 1600, 1600)->nonQueued();
    }

    protected function casts(): array
    {
        return [
            'is_home' => 'boolean',
            'colonne' => 'integer',
            'blocks' => 'array',
            'seo' => 'array',
            'publish_at' => 'datetime',
        ];
    }
}
