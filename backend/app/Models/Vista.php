<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Una riga di statistiche: un giorno, una categoria, un agente, un percorso. */
class Vista extends Model
{
    use BelongsToSite;

    protected $table = 'viste';

    protected $fillable = ['giorno', 'categoria', 'agente', 'percorso', 'conteggio', 'con_js'];

    protected function casts(): array
    {
        return ['giorno' => 'date'];
    }

    public function scopeUltimiGiorni(Builder $query, int $giorni): Builder
    {
        return $query->where('giorno', '>=', now()->subDays($giorni - 1)->startOfDay());
    }
}
