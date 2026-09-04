<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Un indirizzo che ha risposto 404, con quante volte e da dove.
 */
class PaginaMancante extends Model
{
    use BelongsToSite;
    use HasFactory;

    protected $table = 'pagine_mancanti';

    protected $fillable = [
        'site_id', 'percorso', 'colpi', 'colpi_con_referrer',
        'ultimo_referrer', 'primo_il', 'ultimo_il', 'ignorata',
    ];

    protected function casts(): array
    {
        return [
            'colpi' => 'integer',
            'colpi_con_referrer' => 'integer',
            'ignorata' => 'boolean',
            'primo_il' => 'datetime',
            'ultimo_il' => 'datetime',
        ];
    }

    /**
     * Quelli che valgono la pena di essere guardati.
     *
     * Un 404 con un referrer vuol dire che ESISTE un collegamento rotto: su
     * questo sito, o su quello di qualcun altro che ci manda visitatori. Un
     * 404 senza referrer, nella stragrande maggioranza, e' uno scanner che
     * prova /wp-admin. Mostrare tutto insieme trasformerebbe questa pagina in
     * un allarme che si impara a ignorare, che e' peggio di nessun allarme.
     */
    public function scopeDaGuardare(Builder $query): Builder
    {
        return $query->where('ignorata', false)->where('colpi_con_referrer', '>', 0);
    }
}
