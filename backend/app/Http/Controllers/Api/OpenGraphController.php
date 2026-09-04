<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\Post;
use App\Models\Site;
use App\Services\GeneratoreOpenGraph;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Immagini Open Graph, lette dal worker di build.
 *
 * Generare un PNG costa: Imagick disegna, misura il testo e comprime. Il
 * risultato viene messo in cache con una chiave che include updated_at del
 * contenuto, cosi' l'invalidazione e' automatica — cambia il titolo, cambia
 * la chiave — senza dover ricordarsi di svuotare niente.
 */
class OpenGraphController extends Controller
{
    private const TTL = 86400;

    public function __construct(private readonly GeneratoreOpenGraph $generatore) {}

    /** Immagine di una pagina o di un articolo. */
    public function contenuto(Request $request, Site $site, string $slug): Response
    {
        $contenuto = Page::where('slug', $slug)->first()
            ?? Post::where('slug', $slug)->first();

        // Uno slug inesistente non e' un errore da mostrare al mondo: si
        // ricade sull'immagine del sito, cosi' un link vecchio conserva
        // comunque un'anteprima decorosa invece di romperla.
        $titolo = $contenuto->title ?? $site->name ?? $site->domain;
        $marcatore = $contenuto?->updated_at?->timestamp ?? 0;

        return $this->rispondi($site, $titolo, $slug, $marcatore, $request->boolean('ritaglio'));
    }

    /** Immagine predefinita del sito, usata dove non c'e' un contenuto. */
    public function sito(Request $request, Site $site): Response
    {
        return $this->rispondi(
            $site,
            $site->name ?? $site->domain,
            '_sito',
            $site->updated_at?->timestamp ?? 0,
            $request->boolean('ritaglio'),
        );
    }

    private function rispondi(Site $site, string $titolo, string $chiave, int $marcatore, bool $ritaglio): Response
    {
        $config = md5(json_encode([$site->og_config, $site->theme]));
        $cacheKey = "og:{$site->id}:{$chiave}:{$marcatore}:{$config}:" . ($ritaglio ? 'c' : 'f');

        $png = Cache::remember($cacheKey, self::TTL, fn (): string => $ritaglio
            ? $this->generatore->pngRitagliato($site, $titolo)
            : $this->generatore->png($site, $titolo));

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=3600',
            // I social rileggono l'immagine raramente: un ETag stabile evita
            // di rispedirla quando non e' cambiata.
            'ETag' => '"' . md5($cacheKey) . '"',
        ]);
    }
}
