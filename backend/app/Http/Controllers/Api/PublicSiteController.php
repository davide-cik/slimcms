<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\MessaggioDiContatto;
use App\Models\Messaggio;
use App\Models\Page;
use App\Models\Post;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/**
 * Le uniche due funzioni davvero invocate a runtime dal visitatore finale
 * (specifiche 7.3): ricerca interna e form di contatto.
 *
 * Qui NON c'e' token: il sito sta nella URL e lo risolve
 * `RisolviSitoDaParametro`, perche' la chiamata parte dal browser di un
 * visitatore anonimo — che sta sul dominio del sito, mentre l'API risponde
 * su un altro dominio. Da qui il rate limiting, che e' l'unica difesa
 * disponibile su un endpoint pubblico.
 *
 * La ricerca resta qui ma **Astro non la usa**: sui mini siti l'indice sta
 * in un JSON generato in build e la ricerca gira nel browser, senza rete e
 * senza dipendere dal backend per una lettura (sezione 7 delle specifiche).
 * L'endpoint serve a un sito troppo grande perche' l'indice valga la pena di
 * essere scaricato.
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

        $site = app()->bound('currentSite') ? app('currentSite') : null;
        $base = $site?->baseBlog() ?? 'blog';

        // Il global scope confina gia' la ricerca al sito corrente.
        $pagine = Page::query()
            ->where('status', 'published')
            ->where(fn ($q) => $q->where('title', 'like', '%' . $termine . '%')
                ->orWhere('slug', 'like', '%' . $termine . '%'))
            ->limit(20)
            ->get()
            ->map(fn (Page $p) => [
                'title' => $p->title,
                'url' => $p->is_home ? '/' : '/' . $p->slug . '/',
                'summary' => $p->seo['structured_summary'] ?? $p->seo['meta_description'] ?? null,
            ]);

        // Anche gli articoli: cercare su un sito col blog e non trovare gli
        // articoli e' una ricerca che mente.
        $articoli = Post::query()
            ->pubblicati()
            ->where(fn ($q) => $q->where('title', 'like', '%' . $termine . '%')
                ->orWhere('slug', 'like', '%' . $termine . '%'))
            ->limit(20)
            ->get()
            ->map(fn (Post $a) => [
                'title' => $a->title,
                'url' => '/' . $base . '/' . $a->slug . '/',
                'summary' => $a->excerpt ?? $a->seo['meta_description'] ?? null,
            ]);

        $risultati = $pagine->concat($articoli)->take(20)->values();

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
            'page' => ['nullable', 'string', 'max:300'],
        ]);

        // Honeypot: un campo nascosto che un umano non vede e non compila
        // mai. Se arriva pieno e' un bot, e la risposta e' un 200 identico a
        // quello vero: un 422 gli direbbe quale campo togliere. Il messaggio
        // non viene salvato.
        //
        // Prima era una regola di validazione `max:0`, che rispondeva 422
        // mentre il commento accanto prometteva 200 — il controllo insegnava
        // esattamente cio' che diceva di nascondere.
        if (filled($request->input('website'))) {
            return $this->ricevuto();
        }

        $site = app()->bound('currentSite') ? app('currentSite') : null;

        // Prima la riga, poi la mail. Un form che risponde "messaggio
        // ricevuto" e poi si affida solo all'invio e' un modo per perdere
        // richieste commerciali senza accorgersene: se la mail non parte —
        // mailer non configurato, casella piena, destinatario sbagliato — il
        // messaggio deve restare comunque leggibile dal pannello. Prima qui
        // c'era una riga di log con dentro nome ed email del visitatore, che
        // e' il posto sbagliato per i dati di una persona.
        $messaggio = Messaggio::create([
            'nome' => $dati['name'],
            'email' => $dati['email'],
            'messaggio' => $dati['message'],
            'pagina' => $dati['page'] ?? null,
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 300),
        ]);

        $this->avvisa($site, $messaggio);

        return $this->ricevuto();
    }

    /**
     * La stessa identica risposta per un messaggio vero e per uno scartato
     * dall'esca: e' il punto dell'esca.
     */
    private function ricevuto(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'message' => 'Messaggio ricevuto. Ti rispondiamo al piu\' presto.',
        ]);
    }

    /**
     * Avvisa per email il destinatario configurato sul sito.
     *
     * Un invio fallito non deve diventare un errore per il visitatore: il
     * messaggio e' gia' salvato, quindi la cosa peggiore che puo' succedere
     * e' che il titolare lo scopra dal pannello invece che dalla posta. Si
     * annota nel log — dove finisce l'errore, non i dati della persona.
     */
    private function avvisa(?Site $site, Messaggio $messaggio): void
    {
        $destinatario = $site?->contact_email;

        if (blank($destinatario)) {
            return;
        }

        try {
            Mail::to($destinatario)->send(new MessaggioDiContatto($messaggio));
        } catch (\Throwable $e) {
            logger()->error('Avviso di contatto non inviato', [
                'sito' => $site?->domain,
                'messaggio_id' => $messaggio->id,
                'errore' => $e->getMessage(),
            ]);
        }
    }
}
