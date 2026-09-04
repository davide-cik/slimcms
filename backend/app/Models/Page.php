<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Pagina di contenuto di un mini sito.
 *
 * SECONDO livello di isolamento: scoped per site_id.
 */
class Page extends Model
{
    use BelongsToSite;
    use HasFactory;
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

    protected function casts(): array
    {
        return [
            'blocks' => 'array',
            'seo' => 'array',
            'publish_at' => 'datetime',
        ];
    }
}
