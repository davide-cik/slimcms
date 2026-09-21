<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use App\Models\Concerns\PubblicazioneRiservata;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Un prodotto del negozio (SlimShop, specifica §2).
 *
 * Si comporta come una pagina: scoped per sito, pubblicato solo da chi ha
 * il grado di redattore, con i blocchi del page builder per il corpo della
 * scheda. In piu' ha un prezzo e un magazzino.
 */
class Prodotto extends Model implements HasMedia
{
    use BelongsToSite;
    use PubblicazioneRiservata;
    use InteractsWithMedia;
    use SoftDeletes;

    protected $table = 'prodotti';

    protected $fillable = [
        'site_id',
        'nome',
        'slug',
        'descrizione',
        'prezzo',
        'prezzo_barrato',
        'scorte',
        'blocks',
        'seo',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'prezzo' => 'integer',
            'prezzo_barrato' => 'integer',
            'scorte' => 'integer',
            'blocks' => 'array',
            'seo' => 'array',
        ];
    }

    /**
     * Le immagini del prodotto: la prima e' la copertina, i blocchi scelgono
     * fra queste. Stesso nome della collezione delle pagine, cosi' il
     * builder condiviso le trova senza sapere di che modello si tratta.
     */
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

    public function scopePubblicati(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }

    public function disponibile(): bool
    {
        return $this->scorte > 0;
    }
}
