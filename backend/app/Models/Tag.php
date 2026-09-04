<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Tag del blog, scoped per sito come le categorie: due clienti diversi
 * possono avere entrambi un tag "performance" senza collidere.
 *
 * La differenza dalla categoria e' redazionale, non strutturale: la categoria
 * dice di cosa parla l'articolo e ce n'e' una manciata; il tag dice cosa ci
 * si trova dentro e ce ne sono molti. Il modello e' lo stesso perche' le
 * garanzie che servono sono le stesse.
 */
class Tag extends Model
{
    use BelongsToSite;
    use HasFactory;

    protected $fillable = ['site_id', 'name', 'slug'];

    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class);
    }
}
