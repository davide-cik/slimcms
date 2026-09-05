<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;

/**
 * Un visitatore distinto in un giorno.
 *
 * L'impronta e' un hash con un sale che cambia ogni giorno e non viene
 * conservato: conta i distinti di oggi senza poterli riconoscere domani.
 */
class VistaImpronta extends Model
{
    use BelongsToSite;

    protected $table = 'viste_impronte';

    protected $fillable = ['giorno', 'impronta'];

    protected function casts(): array
    {
        return ['giorno' => 'date'];
    }
}
