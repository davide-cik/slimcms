<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Un reindirizzamento del sito: da un vecchio indirizzo a uno nuovo.
 *
 * Non viene consultato a runtime — il sito e' statico. Le righe attive
 * diventano un .htaccess in fase di build.
 */
class Redirect extends Model
{
    use BelongsToSite;
    use HasFactory;

    protected $fillable = ['site_id', 'da', 'a', 'codice', 'attivo', 'nota'];

    protected function casts(): array
    {
        return [
            'codice' => 'integer',
            'attivo' => 'boolean',
        ];
    }

    /**
     * I due soli codici che ha senso offrire.
     *
     * 301 dice ai motori di spostare il posizionamento sul nuovo indirizzo;
     * 302 dice di tenerselo sul vecchio. Un 302 usato al posto di un 301 e'
     * il modo piu' comune di buttare via il lavoro SEO di una pagina, quindi
     * il pannello dice cosa significano invece di mostrare due numeri.
     */
    public const CODICI = [
        301 => 'Permanente (301) — la pagina si e spostata per sempre',
        302 => 'Temporaneo (302) — tornera al vecchio indirizzo',
    ];

    /**
     * Normalizza un percorso di partenza.
     *
     * Sempre con lo slash iniziale e senza quello finale: la regola generata
     * accetta poi entrambe le forme, cosi' non si finisce con un 301 verso un
     * indirizzo che a sua volta ne fa un altro per lo slash.
     */
    public static function normalizza(?string $percorso): string
    {
        $percorso = trim((string) $percorso);

        // Chi incolla l'indirizzo intero non ha sbagliato: e' quello che ha
        // sotto gli occhi in Search Console.
        if (str_contains($percorso, '://')) {
            $percorso = (string) parse_url($percorso, PHP_URL_PATH);
        }

        $percorso = '/' . trim($percorso, '/');
        $percorso = (string) preg_replace('#/{2,}#', '/', $percorso);

        return $percorso;
    }
}
