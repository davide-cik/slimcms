<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\GeneratoreFavicon;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * La favicon del sito in formato ICO, letta dal worker di build.
 *
 * Il file finisce nel `dist/` come tutto il resto e viene servito dal dominio
 * del cliente: `/favicon.ico` e' un indirizzo che i browser chiedono da soli,
 * e deve rispondere il sito, non noi.
 *
 * Rasterizzare costa (Imagick legge, ridisegna tre volte e comprime), quindi
 * il risultato sta in cache con una chiave che contiene i campi da cui
 * dipende: cambia il nome del sito, cambiano le iniziali, cambia la chiave.
 */
class FaviconController extends Controller
{
    private const TTL = 86400;

    public function __construct(private readonly GeneratoreFavicon $generatore) {}

    public function ico(Site $site): Response
    {
        $chiave = 'favicon:ico:' . $site->id . ':' . md5(json_encode([
            $site->favicon_path,
            $site->favicon_initials,
            $site->name,
            $site->theme,
        ]));

        $ico = Cache::remember($chiave, self::TTL, fn (): string => $this->generatore->ico($site));

        return response($ico, 200, [
            'Content-Type' => 'image/x-icon',
            'Cache-Control' => 'public, max-age=3600',
            'ETag' => '"' . md5($chiave) . '"',
        ]);
    }
}
