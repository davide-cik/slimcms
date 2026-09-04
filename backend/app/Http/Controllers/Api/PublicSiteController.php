<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Page;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Le uniche due funzioni davvero invocate a runtime dal visitatore finale
 * (specifiche 7.3): ricerca interna e form di contatto.
 *
 * Qui NON c'e' token: il sito si risolve dall'Host della richiesta tramite
 * ResolveSiteFromDomain, perche' la chiamata parte dal browser di un
 * visitatore anonimo sul dominio del sito stesso. Da qui il rate limiting,
 * che e' l'unica difesa disponibile su un endpoint pubblico.
 */
class PublicSiteController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $termine = trim((string) $request->query('q', ''));

        if (mb_strlen($termine) < 2) {
            return response()->json([
                'query' => $termine,
                'results' => [],
                'message' => 'Servono almeno due caratteri.',
            ]);
        }

        // Il global scope confina gia' la ricerca al sito corrente.
        $risultati = Page::query()
            ->where('status', 'published')
            ->where(fn ($q) => $q->where('title', 'like', '%' . $termine . '%')
                ->orWhere('slug', 'like', '%' . $termine . '%'))
            ->limit(20)
            ->get()
            ->map(fn (Page $p) => [
                'title' => $p->title,
                'url' => $p->slug === 'home' ? '/' : '/' . $p->slug,
                'summary' => $p->seo['structured_summary'] ?? $p->seo['meta_description'] ?? null,
            ]);

        return response()->json([
            'query' => $termine,
            'results' => $risultati,
        ]);
    }

    public function contact(Request $request): JsonResponse
    {
        $dati = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:180'],
            'message' => ['required', 'string', 'max:5000'],
            // Honeypot: un campo che un umano non compila mai. Se arriva
            // pieno e' un bot, e rispondiamo comunque 200 per non insegnargli
            // come aggirare il controllo.
            'website' => ['nullable', 'string', 'max:0'],
        ]);

        $site = app()->bound('currentSite') ? app('currentSite') : null;

        logger()->channel('single')->info('Richiesta di contatto', [
            'site' => $site?->domain,
            'name' => $dati['name'],
            'email' => $dati['email'],
        ]);

        // TODO: invio email al destinatario configurato sul sito, quando
        // sara' definito il campo contact_email su Site.

        return response()->json([
            'ok' => true,
            'message' => 'Messaggio ricevuto. Ti risponderemo al piu' . "\u{0020}" . 'presto.',
        ]);
    }
}
