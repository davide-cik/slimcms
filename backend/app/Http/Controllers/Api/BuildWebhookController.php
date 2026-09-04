<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BuildRequest;
use App\Models\Site;
use App\Services\BuildQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Webhook interno per accodare una rigenerazione (specifiche, sezione 9).
 *
 * Le modifiche fatte in Filament accodano gia' da sole tramite gli observer:
 * questo endpoint serve ai trigger ESTERNI (uno script di migrazione, un
 * import massivo, un sistema di terze parti). Usa la stessa autorizzazione
 * degli endpoint di build: token legato al sito.
 */
class BuildWebhookController extends Controller
{
    public function store(Request $request, Site $site): JsonResponse
    {
        $dati = $request->validate([
            'reason' => ['nullable', 'string', 'max:60'],
            'scope' => ['nullable', 'in:incremental,full'],
            'paths' => ['nullable', 'array', 'max:200'],
            'paths.*' => ['string', 'max:300'],
        ]);

        $richiesta = BuildQueue::accoda(
            $site,
            $dati['reason'] ?? 'webhook',
            $dati['scope'] ?? 'incremental',
            $dati['paths'] ?? null,
        );

        return response()->json([
            'accodata' => true,
            'build_request_id' => $richiesta->id,
            'scope' => $richiesta->scope,
            // Chi chiama deve sapere che NON e' partita subito: il debounce
            // e' un comportamento voluto, non un ritardo da segnalare.
            'parte_fra_secondi' => max(0, (int) now()->diffInSeconds($richiesta->run_after, false)),
        ], 202);
    }

    /** Stato delle build di un sito, per monitoraggio. */
    public function index(Site $site): JsonResponse
    {
        $recenti = BuildRequest::where('site_id', $site->id)
            ->latest('id')->limit(20)->get()
            ->map(fn (BuildRequest $b) => [
                'id' => $b->id,
                'reason' => $b->reason,
                'scope' => $b->scope,
                'status' => $b->status,
                'attempts' => $b->attempts,
                'run_after' => $b->run_after?->toIso8601String(),
                'finished_at' => $b->finished_at?->toIso8601String(),
                'last_error' => $b->last_error,
            ]);

        return response()->json([
            'site' => $site->domain,
            'in_ritardo' => BuildRequest::where('site_id', $site->id)->inRitardo(5)->count(),
            'builds' => $recenti,
        ]);
    }
}
