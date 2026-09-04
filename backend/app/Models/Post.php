<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Articolo del blog. Stessa struttura di Page piu' autore, categorie,
 * tag, estratto e immagine di copertina (specifiche, sezione 5).
 */
class Post extends Model
{
    use BelongsToSite;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'site_id',
        'author_id',
        'title',
        'slug',
        'excerpt',
        'featured_image_path',
        'tags',
        'blocks',
        'seo',
        'status',
        'publish_at',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'blocks' => 'array',
            'seo' => 'array',
            'publish_at' => 'datetime',
        ];
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
