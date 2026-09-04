<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Categoria del blog, scoped per sito: due clienti diversi possono avere
 * entrambi una categoria con lo stesso nome senza collidere.
 */
class Category extends Model
{
    use BelongsToSite;
    use HasFactory;

    protected $fillable = ['site_id', 'name', 'slug', 'description'];

    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class);
    }
}
