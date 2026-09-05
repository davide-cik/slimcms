<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Un accesso, o un tentativo di accesso, a uno dei due pannelli.
 *
 * Non e' scoped per sito: vedi la migrazione. Va letto dal control plane.
 */
class Accesso extends Model
{
    protected $table = 'accessi';

    public const RIUSCITO = 'riuscito';
    public const FALLITO = 'fallito';
    public const USCITA = 'uscita';
    public const BLOCCATO = 'bloccato';

    protected $fillable = [
        'guardia', 'utente_id', 'email', 'nome', 'esito', 'ip', 'user_agent', 'impersonato',
    ];

    protected function casts(): array
    {
        return ['impersonato' => 'boolean'];
    }

    public function scopeFalliti(Builder $query): Builder
    {
        return $query->whereIn('esito', [self::FALLITO, self::BLOCCATO]);
    }

    public function scopeRecenti(Builder $query, int $minuti = 60): Builder
    {
        return $query->where('created_at', '>=', now()->subMinutes($minuti));
    }

    /** L'etichetta leggibile della guardia: le due tabelle utente sono separate di proposito. */
    public function pannello(): string
    {
        return $this->guardia === 'manage' ? 'Gestione piattaforma' : 'Contenuti';
    }
}
