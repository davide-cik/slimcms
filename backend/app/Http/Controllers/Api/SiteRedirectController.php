<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Redirect;
use App\Models\Site;
use App\Services\GeneratoreHtaccess;
use Illuminate\Http\Response;

class SiteRedirectController extends Controller
{
    /**
     * Il .htaccess del sito, gia' compilato.
     *
     * Il worker di build lo scarica e lo deposita nella radice del sito: da
     * li' in poi i redirect li applica Apache, senza che nessuna richiesta
     * pubblica tocchi Laravel.
     *
     * Si consegna il file finito e non l'elenco delle righe perche' la
     * traduzione da redirect a configurazione e' una regola sola e deve stare
     * in un posto solo. Duplicarla nel frontend sarebbe la stessa giuntura
     * che in questo progetto ha gia' prodotto piu' di un guasto.
     */
    public function htaccess(Site $site, GeneratoreHtaccess $generatore): Response
    {
        // Nessun where sul sito: il middleware ha gia' fissato quello
        // corrente e il global scope filtra (contratto API, CLAUDE.md).
        $contenuto = $generatore->genera(Redirect::orderBy('da')->get());

        return response($contenuto, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
