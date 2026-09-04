<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Services\GeneratoreOpenGraph;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Anteprima dell'immagine Open Graph nel pannello di gestione.
 *
 * Rotta separata dall'API perche' qui l'autorizzazione e' la sessione del
 * control plane, non un token di build. Le anteprime NON vanno in cache:
 * servono a vedere subito l'effetto di una modifica appena salvata.
 */
class AnteprimaOpenGraphController extends Controller
{
    public function __invoke(Request $request, Site $site, GeneratoreOpenGraph $generatore): Response
    {
        $utente = auth('manage')->user();

        if ($utente === null) {
            abort(403);
        }

        // Un operatore di assistenza vede solo i clienti assegnati: la stessa
        // regola della lista siti, altrimenti l'anteprima sarebbe una
        // scorciatoia per vedere il sito di un altro cliente.
        if (! $utente->isSuperAdmin() && ! $utente->tenants()->where('tenants.id', $site->tenant_id)->exists()) {
            abort(403);
        }

        $titolo = $request->string('titolo')->toString()
            ?: ($site->name ?? $site->domain ?? 'Titolo di esempio');

        $png = $request->boolean('ritaglio')
            ? $generatore->pngRitagliato($site, $titolo)
            : $generatore->png($site, $titolo);

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'no-store',
        ]);
    }
}
