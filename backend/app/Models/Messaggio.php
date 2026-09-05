<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Un messaggio arrivato dal form di contatto di un sito.
 */
class Messaggio extends Model
{
    use BelongsToSite;
    use HasFactory;

    protected $table = 'messaggi';

    protected $fillable = [
        'nome',
        'email',
        'messaggio',
        'pagina',
        'ip',
        'user_agent',
        'letto_il',
    ];

    protected function casts(): array
    {
        return ['letto_il' => 'datetime'];
    }

    public function scopeDaLeggere(Builder $query): Builder
    {
        return $query->whereNull('letto_il');
    }

    public function segnaLetto(): void
    {
        if ($this->letto_il === null) {
            $this->forceFill(['letto_il' => now()])->save();
        }
    }
}
