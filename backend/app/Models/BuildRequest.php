<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una richiesta di rigenerazione statica per un sito.
 *
 * NON usa BelongsToSite di proposito: le build vengono lette e scritte da
 * comandi in console, dove non c'e' nessun sito corrente, e devono essere
 * visibili tutte insieme per poter essere eseguite in ordine. L'isolamento
 * qui non serve, perche' non sono dati di contenuto: sono lavoro di
 * piattaforma. E' un'esclusione consapevole, elencata in
 * TenantScopeTest::EXCLUDED_MODELS.
 */
class BuildRequest extends Model
{
    protected $fillable = [
        'site_id', 'reason', 'scope', 'paths',
        'status', 'attempts', 'last_error',
        'run_after', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'paths' => 'array',
            'run_after' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** Build pronte da eseguire: in attesa e con la finestra di debounce scaduta. */
    public function scopeDaEseguire(Builder $q): Builder
    {
        return $q->where('status', 'pending')->where('run_after', '<=', now());
    }

    /**
     * Build ferme in coda da troppo tempo. Le specifiche (7.1) chiedono un
     * alert oltre una soglia: questo e' il dato su cui costruirlo.
     */
    public function scopeInRitardo(Builder $q, int $minuti = 5): Builder
    {
        return $q->whereIn('status', ['pending', 'running'])
            ->where('run_after', '<=', now()->subMinutes($minuti));
    }
}
