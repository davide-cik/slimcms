<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\MessaggioDiContatto;
use App\Models\Messaggio;
use App\Models\Modulo;
use App\Models\Page;
use App\Models\Post;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Support\Captcha\FabbricaCaptcha;
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

    /**
     * Una sfida per il captcha semplice.
     *
     * Solo quello semplice ne ha bisogno: Turnstile e reCAPTCHA se la
     * generano nel browser parlando col proprio fornitore. Per gli altri
     * questa rotta risponde con `null` invece di 404, cosi' il sito puo'
     * chiamarla sempre senza sapere in anticipo quale captcha e' configurato.
     */
    public function captcha(): JsonResponse
    {
        $site = app()->bound('currentSite') ? app('currentSite') : null;

        return response()->json([
            'captcha' => FabbricaCaptcha::per($site)->perIlSito(),
            'sfida' => FabbricaCaptcha::per($site)->sfida(),
        ]);
    }

    public function contact(Request $request): JsonResponse
    {
        $site = app()->bound('currentSite') ? app('currentSite') : null;

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

        // Il modulo dice quali campi aspettarsi. Se non arriva — moduli
        // vecchi, o un sito che non ne ha ancora definito nessuno — restano i
        // tre di sempre.
        $modulo = filled($request->input('modulo'))
            ? Modulo::query()->attivi()->where('slug', $request->string('modulo'))->first()
            : null;

        // Il captcha PRIMA della validazione: a un bot non si dice quali
        // campi ha sbagliato.
        $captcha = FabbricaCaptcha::per($site);

        $gettone = $request->input('cf-turnstile-response')
            ?? $request->input('g-recaptcha-response')
            ?? $request->input('captcha_token');

        if (! $captcha->verifica($request->input('captcha'), $gettone, $request->ip())) {
            return response()->json([
                'ok' => false,
                'message' => 'La verifica anti-spam non e\' andata a buon fine. Riprova.',
                'errors' => ['captcha' => ['Verifica non superata.']],
            ], 422);
        }

        $regole = [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:180'],
            'message' => ['required', 'string', 'max:5000'],
            'page' => ['nullable', 'string', 'max:300'],
        ];

        foreach ($modulo?->campiNormalizzati() ?? [] as $campo) {
            $regole['dati.' . $campo['nome']] = $this->regolePerCampo($campo);
        }

        $dati = $request->validate($regole);

        // Prima la riga, poi la mail. Un form che risponde "messaggio
        // ricevuto" e poi si affida solo all'invio e' un modo per perdere
        // richieste commerciali senza accorgersene: se la mail non parte —
        // mailer non configurato, casella piena, destinatario sbagliato — il
        // messaggio deve restare comunque leggibile dal pannello. Prima qui
        // c'era una riga di log con dentro nome ed email del visitatore, che
        // e' il posto sbagliato per i dati di una persona.
        $messaggio = Messaggio::create([
            'modulo_id' => $modulo?->getKey(),
            'nome' => $dati['name'],
            'email' => $dati['email'],
            'messaggio' => $dati['message'],
            'dati' => $this->extra($modulo, $dati['dati'] ?? []),
            'pagina' => $dati['page'] ?? null,
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 300),
        ]);

        $this->avvisa($site, $messaggio, $modulo);

        return $this->ricevuto($modulo?->messaggio_conferma);
    }

    /**
     * Le regole di validazione di un campo definito nel pannello.
     *
     * @param  array{nome: string, tipo: string, obbligatorio: bool, opzioni: list<string>}  $campo
     * @return list<mixed>
     */
    private function regolePerCampo(array $campo): array
    {
        $regole = [$campo['obbligatorio'] ? 'required' : 'nullable'];

        return array_merge($regole, match ($campo['tipo']) {
            'email' => ['email', 'max:180'],
            'numero' => ['numeric'],
            'telefono' => ['string', 'max:40'],
            'testo_lungo' => ['string', 'max:5000'],
            // Una scelta fuori elenco non e' un refuso: e' qualcuno che ha
            // cambiato il valore prima di inviare.
            'scelta' => $campo['opzioni'] === [] ? ['string', 'max:200'] : ['in:' . implode(',', $campo['opzioni'])],
            'consenso' => ['accepted'],
            default => ['string', 'max:200'],
        });
    }

    /**
     * Gli extra da salvare, con le etichette accanto ai valori.
     *
     * Le etichette si copiano nel messaggio invece di risolverle ogni volta
     * dal modulo: un campo rinominato o cancellato l'anno prossimo non deve
     * rendere illeggibile un messaggio ricevuto oggi.
     *
     * @return array<int, array{etichetta: string, valore: mixed}>
     */
    private function extra(?Modulo $modulo, array $valori): array
    {
        $extra = [];

        foreach ($modulo?->campiNormalizzati() ?? [] as $campo) {
            if (! array_key_exists($campo['nome'], $valori)) {
                continue;
            }

            $extra[] = [
                'etichetta' => $campo['etichetta'],
                'valore' => $valori[$campo['nome']],
            ];
        }

        return $extra;
    }

    /**
     * La stessa identica risposta per un messaggio vero e per uno scartato
     * dall'esca: e' il punto dell'esca.
     */
    private function ricevuto(?string $conferma = null): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'message' => $conferma ?: 'Messaggio ricevuto. Ti rispondiamo al piu\' presto.',
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
    private function avvisa(?Site $site, Messaggio $messaggio, ?Modulo $modulo = null): void
    {
        // Il destinatario del modulo, altrimenti quello del sito: un sito
        // puo' avere un indirizzo generale e un modulo "lavora con noi" che
        // va da un'altra parte.
        $destinatario = $modulo?->destinatario() ?? $site?->contact_email;

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
