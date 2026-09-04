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
        'blocks',
        'seo',
        'status',
        'publish_at',
    ];

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
            'blocks' => 'array',
            'seo' => 'array',
            'publish_at' => 'datetime',
        ];
    }
}
