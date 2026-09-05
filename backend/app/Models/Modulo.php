<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use App\Support\Slug;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un modulo del sito: nome, campi, destinatario.
 *
 * I tre campi di ogni modulo di contatto — nome, email, messaggio — non si
 * dichiarano: ci sono sempre. Sono quelli su cui si cerca e si ordina
 * nell'elenco dei messaggi, quindi stanno su colonne e non nel JSON. `campi`
 * elenca solo quelli in piu'.
 */
class Modulo extends Model
{
    use BelongsToSite;
    use HasFactory;

    protected $table = 'moduli';

    /** I tipi di campo che il sito sa disegnare. Vedi ModuloContatto.astro. */
    public const TIPI = [
        'testo' => 'Testo breve',
        'testo_lungo' => 'Testo lungo',
        'email' => 'Email',
        'telefono' => 'Telefono',
        'numero' => 'Numero',
        'scelta' => 'Scelta fra opzioni',
        'consenso' => 'Casella di consenso',
    ];

    protected $fillable = [
        'nome', 'slug', 'email_destinatario', 'campi', 'messaggio_conferma', 'attivo',
    ];

    protected function casts(): array
    {
        return ['campi' => 'array', 'attivo' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $modulo): void {
            if (blank($modulo->slug)) {
                $modulo->slug = Slug::da($modulo->nome);
            }
        });
    }

    public function messaggi(): HasMany
    {
        return $this->hasMany(Messaggio::class);
    }

    public function scopeAttivi(Builder $query): Builder
    {
        return $query->where('attivo', true);
    }

    /** Il destinatario: quello del modulo, altrimenti quello del sito. */
    public function destinatario(): ?string
    {
        if (filled($this->email_destinatario)) {
            return $this->email_destinatario;
        }

        return Site::withoutTenancy()->find($this->site_id)?->contact_email;
    }

    /**
     * I campi in piu', normalizzati.
     *
     * Il pannello puo' salvare righe incomplete mentre si scrive; qui si
     * scartano invece di consegnarle al sito, che disegnerebbe un campo
     * senza nome — cioe' un campo che non arriva mai.
     *
     * @return list<array{nome: string, etichetta: string, tipo: string, obbligatorio: bool, opzioni: list<string>}>
     */
    public function campiNormalizzati(): array
    {
        $visti = [];

        return collect($this->campi ?? [])
            ->filter(fn ($c) => is_array($c) && filled($c['etichetta'] ?? null))
            ->map(function (array $c) use (&$visti): ?array {
                $nome = Slug::da($c['nome'] ?? $c['etichetta']);
                $nome = str_replace('-', '_', $nome);

                // Un nome vuoto o gia' usato romperebbe l'invio in silenzio:
                // due campi con lo stesso nome si sovrascrivono a vicenda.
                if ($nome === '' || in_array($nome, $visti, true) || in_array($nome, ['name', 'email', 'message', 'website', 'page', 'modulo', 'captcha'], true)) {
                    return null;
                }

                $visti[] = $nome;

                return [
                    'nome' => $nome,
                    'etichetta' => (string) $c['etichetta'],
                    'tipo' => array_key_exists($c['tipo'] ?? '', self::TIPI) ? $c['tipo'] : 'testo',
                    'obbligatorio' => (bool) ($c['obbligatorio'] ?? false),
                    'opzioni' => collect($c['opzioni'] ?? [])
                        ->map(fn ($o) => is_array($o) ? (string) ($o['valore'] ?? '') : (string) $o)
                        ->filter()
                        ->values()
                        ->all(),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
