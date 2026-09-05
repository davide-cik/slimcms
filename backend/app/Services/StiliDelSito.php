<?php

namespace App\Services;

use App\Models\Site;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * I fogli di stile del sito pubblicato, per l'anteprima nel pannello.
 *
 * L'anteprima carica il CSS **vero** del sito invece di tenerne una copia.
 * E' la differenza fra un'anteprima che assomiglia al sito e una che gli
 * somigliava sei mesi fa: qui non c'e' niente da tenere allineato, perche'
 * i fogli sono gli stessi file che serve il dominio del cliente.
 *
 * Si legge la home pubblicata e se ne prendono i `<link rel="stylesheet">`.
 * Se il sito non e' mai stato pubblicato non c'e' niente da leggere, e
 * l'anteprima lo dice invece di mostrare una pagina senza stile spacciandola
 * per il risultato.
 */
class StiliDelSito
{
    private const TTL = 300;

    /** @return array<int, string> URL assolute dei fogli di stile */
    public function per(Site $site): array
    {
        return Cache::remember("stili:{$site->id}", self::TTL, function () use ($site): array {
            $base = 'https://' . $site->domain;

            try {
                // Timeout corto: l'anteprima non deve restare appesa perche'
                // il sito di un cliente e' lento o irraggiungibile.
                $risposta = Http::timeout(4)->get($base . '/');
            } catch (\Throwable) {
                return [];
            }

            if (! $risposta->successful()) {
                return [];
            }

            preg_match_all(
                '/<link[^>]+rel=["\']stylesheet["\'][^>]*>/i',
                $risposta->body(),
                $tag
            );

            $fogli = [];

            foreach ($tag[0] as $t) {
                if (! preg_match('/href=["\']([^"\']+)["\']/i', $t, $m)) {
                    continue;
                }

                $href = $m[1];

                // Solo i fogli del sito stesso: un `<link>` verso un dominio
                // qualsiasi finirebbe caricato dentro il pannello.
                if (str_starts_with($href, '/')) {
                    $fogli[] = $base . $href;
                } elseif (str_starts_with($href, $base . '/')) {
                    $fogli[] = $href;
                }
            }

            return array_values(array_unique($fogli));
        });
    }
}
