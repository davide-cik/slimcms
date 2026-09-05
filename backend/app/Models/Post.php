<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use App\Models\Concerns\PubblicazioneRiservata;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Articolo del blog. Stessa struttura di Page piu' autore, categorie,
 * tag, estratto e immagine di copertina (specifiche, sezione 5).
 */
class Post extends Model implements HasMedia
{
    use BelongsToSite;
    use PubblicazioneRiservata;
    use HasFactory;
    use InteractsWithMedia;
    use SoftDeletes;

    protected $fillable = [
        'site_id',
        'author_id',
        'title',
        'slug',
        'excerpt',
        'featured_image_path',
        'blocks',
        'seo',
        'status',
        'publish_at',
    ];

    protected function casts(): array
    {
        return [
            'blocks' => 'array',
            'seo' => 'array',
            'publish_at' => 'datetime',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('copertina')
            ->singleFile()
            ->useDisk(config('media-library.disk_name'));
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('anteprima')->fit(Fit::Contain, 320, 320)->nonQueued();
        $this->addMediaConversion('web')->fit(Fit::Max, 1600, 1600)->nonQueued();
    }

    /** URL della copertina, se c'e'. */
    public function copertinaUrl(string $conversione = 'web'): ?string
    {
        $m = $this->getFirstMedia('copertina');

        return $m?->hasGeneratedConversion($conversione) ? $m->getUrl($conversione) : $m?->getUrl();
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    /**
     * I tag erano una colonna JSON di stringhe. Se quella colonna tornasse,
     * `$post->tags` risolverebbe all'attributo e non a questa relazione, e
     * `whenLoaded('tags')` restituirebbe silenziosamente niente: la colonna
     * e' stata rimossa nella stessa migrazione che ha creato la tabella.
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    /**
     * Articoli effettivamente visibili al pubblico.
     *
     * Non basta status = published: un articolo programmato resta published
     * con publish_at nel futuro e non deve comparire finche' non e' il momento.
     */
    public function scopePubblicati(Builder $query): Builder
    {
        return $query->where('status', 'published')
            ->where(fn (Builder $q) => $q->whereNull('publish_at')->orWhere('publish_at', '<=', now()));
    }
}
