# SlimCMS

Piattaforma CMS multitenant che sostituisce WordPress nella gestione di molti mini siti
aziendali/blog. Backend Laravel + Filament disaccoppiato, frontend pubblico Astro statico.

**La specifica completa e autorevole è `docs/slimcms-specifiche-tecniche.md`.** Questo file
contiene solo ciò che serve operativamente a ogni sessione. In caso di dubbio sul *cosa*
costruire, leggi la specifica; qui trovi il *come* e i vincoli non negoziabili.

## Stack (versioni decise, non cambiarle senza chiedere)

| Componente | Versione | Note |
|---|---|---|
| PHP | 8.2 (`/usr/bin/php8.2`) | vincola Laravel a 12.x (Laravel 13 richiede PHP ^8.3) |
| Laravel | ^12.0 | **non** aggiornare a 13 senza prima aggiornare PHP |
| Filament | ^5.0 | Livewire 4. MFA/TOTP nativa (`Auth\MultiFactor\App\AppAuthentication` + middleware `EnsureMultiFactorAuthenticationIsEnabled`): nessun package extra |
| Multitenancy | `stancl/tenancy` ^3.10 | modello **single database** con scoping per colonna |
| Database | MariaDB 10.6.23 **remota su `10.0.0.3`** | `claudio_slimcms` = sviluppo **e test**, `claudio_slimcms_prod` = produzione. NON è il MariaDB locale |
| Queue / cache | Redis 6.0 (locale, attivo) | driver `database` accettabile solo in test |
| Frontend | Astro 7.3.1, output `static` | Node 24.13. `hybrid` (citato nelle specifiche) e' stato RIMOSSO in Astro 7: `static` e' il default e fa la stessa cosa |
| Auth API | Laravel Sanctum ^4 | token per il worker di build Astro |

Docker **non** è disponibile su questa macchina: tutto gira su servizi di sistema.

Il database è **remoto** (`10.0.0.3`), non locale: sulla macchina di sviluppo gira anche una
MariaDB locale che **non c'entra nulla** con il progetto — non puntarci mai. Redis invece è
quello locale.

I test girano su `claudio_slimcms`, lo stesso database dello sviluppo: `RefreshDatabase` lo
**azzera** a ogni esecuzione. Non è un problema perché i seeder lo ricostruiscono per intero
(`ContenutoHomeSlimcms` contiene il contenuto reale della home di slimcms.it), ma vuol dire
che dopo ogni `php artisan test` va lanciato `php artisan db:seed`.

Backup notturni alle 03:30 via cron: `scripts/backup-db.sh`, credenziali in
`~/.slimcms/*.cnf` (fuori dal repo), rotazione 30 daily + 4 weekly + 2 monthly per database.

## Layout monorepo

```
slimcms/
├── CLAUDE.md
├── docs/            # specifica + codice sorgente di riferimento (NON cancellare né spostare)
├── backend/         # app Laravel: data plane + control plane, due pannelli Filament
└── frontend/        # sito Astro
```

## Comandi

Eseguire sempre da `backend/` (o `frontend/`), mai dalla radice.

```bash
# backend
# ATTENZIONE: `php artisan serve` NON funziona su questa macchina: php.ini
# disabilita pcntl_* e exec (restrizione dell'hosting). Usa il server built-in:
php -S 127.0.0.1:8000 -t public public/index.php
php artisan migrate                   # migrazioni
php artisan test                      # suite completa. ATTENZIONE: azzera claudio_slimcms
                                      # (RefreshDatabase). Dopo: php artisan db:seed
php artisan test --filter=TenantScope # il test di sicurezza multitenant (vedi sotto)
php artisan queue:work --queue=builds,default
php artisan horizon                   # dashboard code

# frontend
npm run dev
npm run build
```

## Le tre regole non negoziabili

Sono regole di **sicurezza**, non di stile. Una violazione = dati di un cliente visibili a
un altro cliente. Se una di queste ti sembra d'intralcio, fermati e chiedi.

### 1. Ogni modello con dati di un cliente è scoped, sempre

Ci sono **due livelli distinti** di isolamento e vanno tenuti separati:

| Livello | Colonna | Trait da usare | Modelli |
|---|---|---|---|
| Tenant (il cliente) | `tenant_id` (string) | `Stancl\Tenancy\Database\Concerns\BelongsToTenant` | `Site` |
| Sito (il singolo mini sito) | `site_id` (int) | `App\Models\Concerns\BelongsToSite` | `Page`, `Post`, `Media`, e ogni futuro modello di contenuto |

`Site` **è** scoped per `tenant_id`: nessuna query può leggere il `Site` di un altro
cliente nemmeno per errore. Questo dà difesa in profondità, perché `Page` è a sua volta
scoped per `site_id`.

Attenzione: `tenants.id` è una **stringa** (UUID) nella migrazione di default di stancl,
quindi `Site.tenant_id` è `string`, non `unsignedBigInteger`.

Il trait di stancl usa una proprietà **statica** `$tenantIdColumn` condivisa da tutti i
modelli: gestisce un solo nome di colonna a livello globale. Per questo il livello
`site_id` ha un trait nostro separato, e per questo l'abbiamo rinominato `BelongsToSite`
(due trait con lo stesso basename `BelongsToTenant` collidono sul metodo
`bootBelongsToTenant()` se finiscono sullo stesso modello).

### 2. Mai fidarsi del contesto tenant in console e nelle queue

`BelongsToSite::creating()` auto-assegna `site_id` dal contesto corrente. In un comando
artisan, in un seeder o in un job **il contesto può non essere inizializzato**, e il
record viene inserito con `site_id = NULL`: fallimento silenzioso, il peggiore.

Regola: in job, comandi e seeder **inizializza sempre il tenant esplicitamente**
(`tenancy()->initialize($tenant)` + binding del sito corrente) prima di toccare modelli
scoped. Non affidarti mai all'auto-assegnazione. Abilita `QueueTenancyBootstrapper` in
`config/tenancy.php` perché il contesto si propaghi nei job accodati.

Per bypassare volutamente lo scope: `Page::withoutSiteScope()` (nostro) o
`Site::withoutTenancy()` (macro di stancl). Solo con motivazione esplicita, mai per comodità.

### 2-bis. `forceDelete()` su una query bypassa i global scope

Trappola di Laravel, verificata sul sorgente e **incontrata davvero in sviluppo** (ha
cancellato le pagine di un altro sito sul DB dev):

| Forma | Percorso interno | Scope |
|---|---|---|
| `Page::query()->delete()` | `toBase()` → `applyScopes()` | ✅ applicato |
| `Page::query()->forceDelete()` | `$this->query->delete()`, query builder grezzo | ❌ **bypassato** |
| `$page->forceDelete()` (istanza) | `setKeysForSaveQuery()`, vincolo su chiave primaria | ✅ sicuro |

`Builder::forceDelete()` non chiama `applyScopes()`: la delete attraversa tutti i tenant.
Su un modello con `SoftDeletes` questo significa che **una riga sola di codice svuota la
tabella di tutti i clienti**.

Regola: non usare mai `forceDelete()` nella forma query. Per cancellare davvero, itera e
chiama `forceDelete()` sulle istanze, oppure usa `delete()` che è correttamente scoped.
`tests/Feature/SiteIsolationTest.php` fissa questa differenza.

### 3. L'isolamento si verifica lato Laravel, mai lato Astro

Nessun controllo di appartenenza va delegato al frontend. Astro riceve solo ciò che l'API
ha già filtrato.

Ci sono due reti di sicurezza, con garanzie diverse e complementari:

- `tests/Unit/TenantScopeTest.php` — statico: i trait sono attaccati ai modelli giusti
- `tests/Feature/SiteIsolationTest.php` — comportamentale: lo scope **filtra** davvero

Il secondo è quello che protegge i dati dei clienti; il primo impedisce di dimenticare il
trait su un modello nuovo. Servono entrambi.

`backend/tests/Unit/TenantScopeTest.php` è la rete di sicurezza: scansiona tutti i modelli
e fallisce se uno ha una colonna di scoping senza il trait corrispondente (e viceversa).
**Deve restare verde.**

Il test va costruito su una mappa colonna → trait, non su un trait solo:

```php
private const TENANT_COLUMN_TRAITS = [
    'tenant_id' => \Stancl\Tenancy\Database\Concerns\BelongsToTenant::class,
    'site_id'   => \App\Models\Concerns\BelongsToSite::class,
];
```

`Site` **non** va in `EXCLUDED_MODELS`: ha `tenant_id` e deve usare il trait di stancl.
Se un test fallisce su un modello, la correzione è **aggiungere il trait mancante**, mai
aggiungere il modello alle esclusioni per far tornare il verde. `EXCLUDED_MODELS` è
riservato ai modelli di piattaforma senza colonna di scoping (`Tenant`, `Plan`,
`AdminUser`) e ogni voce richiede un commento che ne spieghi il perché.

## Deprecation di stancl/tenancy

`stancl/tenancy` 3.10 accede alla proprietà statica `$tenantIdColumn` direttamente sul
trait, cosa deprecata da PHP 8.2, e lo fa a **ogni query** su un modello scoped per tenant.

È silenziata in `AppServiceProvider::silenceDeprecationsOnConsole()` togliendo
`E_DEPRECATED` da `error_reporting()`. Serve perché PsySH decide se stampare con
`$errno & error_reporting()`, ignorando la propria `errorLoggingLevel`, e Laravel imposta
`error_reporting(-1)` in bootstrap.

Le deprecation restano registrate in `storage/logs/deprecations.log` nei comandi artisan e
nelle richieste web. Dentro `tinker` no: PsySH sostituisce l'error handler di Laravel (non
venivano registrate nemmeno prima, venivano solo stampate).

Da rimuovere quando stancl sistemerà la cosa a monte.

## Architettura in breve

- **Control plane** (`manage.slimcms.it`): pannello Filament separato, risorse `Tenant`,
  `AdminUser`, `Plan`, `Billing`. Provisioning dei tenant. Un utente redattore non deve
  poterlo vedere in nessun caso.
- **Data plane** (`<dominio-sito>/admin`): stessa app Laravel, secondo pannello Filament,
  multi-tenancy nativa di Filament per il selettore sito quando un utente ne gestisce più d'uno.
- **Lettura pubblica**: completamente statica da CDN, non tocca Laravel. Laravel entra in
  gioco solo in **scrittura** (pubblicazione → webhook → `BuildSitePagesJob` su coda Redis)
  e per le poche funzioni dinamiche: ricerca interna, form contatto.
- **Astro non fa fetch a runtime** per il contenuto: lo riceve in fase di build.

## Libreria media

Basata su `spatie/laravel-medialibrary`, con **due modifiche non standard che sono
strutturali, non cosmetiche**:

1. **`site_id` aggiunto alla tabella `media`** e modello `App\Models\Media` con
   `BelongsToSite`, registrato in `config/media-library.php → media_model`. La tabella di
   Spatie è solo polimorfa (`model_type`/`model_id`): senza colonna di scoping sfuggirebbe
   al global scope su cui poggia tutto l'isolamento, e il file di un cliente sarebbe
   elencabile da un altro. Con la colonna, `TenantScopeTest` la copre automaticamente.
2. **`TenantPathGenerator`** produce `tenants/<tenant-id>/media/<id>/` come da specifiche
   §3. Il default di Spatie usa solo l'id del media: i file di clienti diversi finirebbero
   mescolati nella stessa cartella, rendendo impossibile un backup, una cancellazione o una
   migrazione per singolo cliente senza interrogare il database file per file.

Il tenant si ricava dal **sito del media**, non dal contesto della richiesta: le conversioni
girano in coda, dove il contesto non c'è.

| Dove | Collezione |
|---|---|
| `Site` | `libreria` — il magazzino del sito |
| `Post` | `copertina` (`singleFile`) |
| `Page` | `immagini` — la libreria della pagina, da cui pescano i blocchi |

Conversioni `anteprima` (320px) e `web` (max 1600px), entrambe `nonQueued` perché il worker
daemon qui non gira (vedi Coda di build).

Il disco è `media` in `config/filesystems.php`. Per passare a Cloudflare R2 in produzione
basta cambiare `MEDIA_DISK`: i percorsi non dipendono dal driver.

I blocchi **non caricano file**: salvano l'`uuid` di un media della pagina, e
`PageResource` lo risolve in url + alt prima di consegnarlo ad Astro. Non e' una
preferenza di stile. `SpatieMediaLibraryFileUpload` dentro un blocco del Builder
allega il file alla pagina e poi **cancella la chiave dallo stato del blocco**: il
blocco resta senza sapere quali immagini siano le sue, la galleria online e' un
contenitore vuoto, e due gallerie sulla stessa pagina si vedono addosso le immagini
l'una dell'altra. Il campo di upload sta quindi **fuori** dal builder
(`Immagini della pagina`), e ogni blocco sceglie da li'.

L'`alt` sta nelle `custom_properties` del **file**, non sulla pagina che lo usa: segue
l'immagine ovunque venga riusata. La lista in `/admin` segnala in giallo i file che ne sono
privi.

`php artisan slimcms:media-orfani` trova i file rimasti sul disco senza riga nel database —
succede dopo ogni `php artisan test`, che azzera il DB ma non il disco. Di default elenca
soltanto; `--elimina` cancella davvero.

## Blog

Articoli, indice, archivi di categoria e di tag. Il segmento sotto cui vivono è
**configurabile per sito** (`/blog/`, `/news/`, `/articoli/`): `Site::baseBlog()` lo
normalizza e `SiteResource` lo espone come `base_blog` già pulito, perché compare in tre
posti che devono concordare — sitemap, JSON-LD e rotte generate da Astro.

Per questo il frontend ha **una sola rotta**, `src/pages/[...percorso].astro`, che smista
su un discriminatore nelle props. Una cartella `src/pages/blog/` sarebbe una bugia il
giorno in cui un cliente sceglie "news", e due rotte rest allo stesso livello sono
ambigue per Astro. Se `base_blog` non arriva (backend più vecchio del frontend) la build
**si ferma**: senza il controllo produceva `/undefined/<slug>/` — pagine valide, del peso
giusto, a indirizzi inventati.

Gli archivi si ricavano **dagli articoli**, non da un elenco di termini: un `Tag` resta in
tabella quando l'ultimo articolo che lo usava torna bozza, e un archivio vuoto è una pagina
sottile. Così esistono esattamente i termini presenti su un articolo pubblicato, e i
collegamenti dentro gli articoli non possono puntare nel vuoto.

`Base.astro` prende un `Documento` (percorso canonico, SEO, og, jsonld), **non** una
`Pagina`: un articolo non è una pagina, e passarglielo avrebbe compilato senza errori
producendo un `Article` senza autore né `datePublished`. Ogni tipo costruisce il proprio
grafo — `grafoJsonLd`, `grafoArticoloJsonLd` (BlogPosting, autore, `articleSection`,
`keywords`), `grafoArchivioJsonLd` (CollectionPage + ItemList).

Le immagini Open Graph degli articoli stanno in `/og/articoli/<slug>.png`, separate da
quelle delle pagine: lo stesso slug può esistere in **entrambe** le tabelle. Il tipo entra
anche nella **chiave di cache** del backend — senza, due contenuti omonimi aggiornati nello
stesso secondo si scambiavano l'immagine. Il gate di deploy verifica ogni `og:image`, che
non compare come `src=` e sfuggiva al controllo delle immagini.

## Ricerca e modulo di contatto

Sono le due funzioni che le specifiche (§7.3) chiamano "dinamiche". Sono finite in due
posti diversi, e non per capriccio.

### La ricerca gira nel browser

`/ricerca-indice.json` si genera in build come la sitemap, e la pagina `/cerca/` lo filtra
lato client. Nessuna chiamata all'API: e' la §7 applicata alla lettera — il sito pubblico
non tocca Laravel per leggere. In cambio i risultati sono istantanei, funzionano a backend
fermo, non consumano rate limit e non fanno una richiesta di rete per ogni tasto premuto.
L'indice non invecchia: ogni pubblicazione accoda una build e la build lo riscrive.

Il testo indicizzato e' troncato a 1200 caratteri per documento: senza un tetto, dieci
pagine lunghe diventano un file da centinaia di kilobyte che ogni visitatore scarica per
cercare una parola. La normalizzazione toglie gli accenti nei due sensi ("citta" trova
"città"), e il titolo pesa piu' del corpo — chi cerca "contatti" vuole la pagina che si
chiama cosi', non quella che la nomina.

`GET /api/public/{sito}/search` resta e **Astro non lo usa**: serve a un sito abbastanza
grande da rendere l'indice troppo pesante da scaricare. Cerca su pagine **e** articoli:
una ricerca che su un sito col blog non trova gli articoli e' una ricerca che mente.

La pagina `/cerca/` e' `noindex` (una pagina di ricerca vuota e' contenuto sottile) e non
sta nel menu: la voce la aggiunge il cliente dal pannello, come qualsiasi altra. Dalla
pagina 404 ci si arriva, perche' chi si e' perso e' esattamente chi ha bisogno di cercare.

### Il contatto e' l'unica cosa che scrive

Blocco `modulo_contatto` del page builder, quindi il cliente lo mette dove vuole. Il
browser chiama `POST /api/public/{sito}/contact`.

**Il sito sta nella URL, non nell'Host.** Il sito statico vive su `<dominio-cliente>` e
l'API su `manage.slimcms.it`: la chiamata e' per forza cross-origin, e l'Host che arriva
all'API e' sempre quello dell'API. Con la risoluzione dall'Host — come era scritto prima —
questi endpoint rispondevano **404 a chiunque**, ed e' il motivo per cui non li usava
nessuno. Servirli dal dominio del cliente richiederebbe un proxy Apache, e qui
`mod_proxy_http` non e' abilitato (serve root).

Da cui anche il CORS, in `config/cors.php`, limitato a `api/public/*`: il resto dell'API
non risponde a nessuna origine. L'origine e' aperta di proposito — non viaggiano ne' cookie
ne' token, e mandare un messaggio dal form e' comunque una cosa che chiunque puo' fare con
`curl`. La difesa e' il rate limiting e l'esca, non un elenco di origini che darebbe
l'impressione di una difesa che non c'e'.

**Il messaggio va in tabella PRIMA della mail.** Un form che risponde "messaggio ricevuto"
e si affida solo all'invio e' un modo per perdere richieste commerciali senza accorgersene:
mailer non configurato, casella piena, destinatario sbagliato. Con la riga in `messaggi`,
la cosa peggiore che puo' succedere e' che il titolare lo scopra dal pannello invece che
dalla posta. Il destinatario e' `sites.contact_email`, e se e' vuoto non parte niente ma il
messaggio si salva lo stesso.

Nell'email il mittente **non** e' l'indirizzo del visitatore: una mail che dichiara un
mittente di un dominio che non e' il nostro viene scartata da SPF e DKIM. Il visitatore sta
nel `Reply-To`, che fa funzionare "Rispondi".

L'esca (honeypot) risponde **200, identico a un invio riuscito**, e non salva. Prima era
una regola di validazione `max:0` che rispondeva 422 mentre il commento accanto prometteva
il contrario: il controllo insegnava al bot esattamente quale campo togliere.

## Favicon

Ogni sito pubblica `/favicon.ico` — un ICO vero con dentro 16, 32 e 48 — e, quando l'icona
e' quella generata dalle iniziali, anche `/favicon.svg`. L'ICO non compare in nessun href
per volere del browser: lo chiede da solo alla radice del dominio, e con lui ogni crawler.
Finche' non c'era, ogni visita produceva un 404 — i primi che il monitoraggio dei 404 ha
registrato su slimcms.it.

### Un SVG caricato non e' un'immagine: e' un documento

**Non si accettano SVG in caricamento, e non e' pedanteria.** Un SVG puo' portare
`<image xlink:href="text:/percorso/di/un/file">`, e il renderer interno di ImageMagick quel
riferimento lo segue e **disegna il contenuto del file dentro l'immagine**. L'immagine e'
la favicon, che finisce pubblicata sul sito: leggibile da chiunque, senza token.

Riprodotto su questa macchina durante una revisione di sicurezza, in due varianti: con
`file://` usciva il PNG dei media di **un altro cliente** — la regola numero uno di questo
file, violata da un campo di upload — e con `text:` il contenuto di un file di testo
qualsiasi leggibile dal processo, `.env` compreso.

La `policy.xml` di ImageMagick su questo host blocca `URL`, `HTTP`, `HTTPS`, `@*` e i coder
PostScript/PDF, ma **non** `text:`, `label:` e `caption:`. Appoggiarsi alla policy dell'host
e' comunque fragile: il controllo sta in `GeneratoreFavicon::fileCaricato()`, che accetta
solo PNG, ICO, JPEG e WebP **riconosciuti dai primi byte** — non dall'estensione, non dal
mime dichiarato, che li sceglie chi carica.

Un ripulitore di SVG sarebbe la soluzione generale ed e' anche il genere di codice che si
sbaglia in silenzio. Una favicon non ha bisogno di vettoriale caricato: quello lo generiamo
noi.

Per lo stesso motivo l'SVG del cliente non viene **ripubblicato**: verrebbe servito
dall'origine del suo sito, dove un `<script>` dentro l'SVG e' codice che gira in
quell'origine. Con un file caricato il sito dichiara solo l'ICO.

`favicon_path` e' un percorso su un disco **privato** del backend, non un indirizzo: non
esce dall'API. Usato com'era, come href nell'HTML del sito statico, dava un 404 sicuro — ed
e' il motivo per cui "Carica un file" non ha mai funzionato prima.

## Reindirizzamenti (301/302)

Il sito pubblico è statico: un redirect non può essere una query. Le righe attive
vengono **compilate** in un `.htaccess` durante la build e depositate nel sito — stesso
principio della mappa di routing (§7.2), la risoluzione di un indirizzo non sta nel
percorso di lettura.

Verificato sul server: `AllowOverride All`, `mod_alias` e `mod_rewrite` rispondono
entrambi, e nginx passa ad Apache tutti gli indirizzi "puliti" con lo slash finale, che
sono quelli che usiamo.

`GET /api/sites/{site}/htaccess` consegna **il file già compilato**, non l'elenco delle
righe: la regola di come un redirect diventa configurazione Apache sta in un posto solo.
Il file non può essere una rotta di Astro (i nomi che iniziano con un punto non sono
indirizzi validi) né essere messo a mano nella document root (`rsync --delete` lo
cancellerebbe): la rotta `htaccess.txt` lo genera e l'integrazione `slimcms-htaccess`
lo rinomina a fine build.

`GeneratoreHtaccess` **appiattisce le catene** (A→B→C diventa A→C: due giri di rete a
ogni visita, e i motori ne seguono un numero limitato) e **toglie gli anelli**: lasciare
l'ultima tappa raggiunta produrrebbe un redirect su sé stesso, cioè un browser che gira
finché non si arrende — peggio del 404 che si stava evitando. Ogni regola porta
`RewriteCond %{REQUEST_FILENAME} !-f/-d`, così un redirect non può rendere irraggiungibile
una pagina pubblicata.

**Un `.htaccess` malformato fa rispondere 500 ad Apache su tutto il sito**, non solo sugli
indirizzi reindirizzati: è il file più pericoloso che pubblichiamo. Per questo il filtro
sugli spazi e i caratteri di controllo è sia nel form sia nel generatore, e il gate di
deploy verifica che il file contenga solo le direttive previste.

`ErrorDocument 404 /404.html` sta nello stesso file: senza, ogni cliente mostra il 404
inglese di HestiaCP.

### Trappola: `actingAs` invalida i test sui permessi dei token

`$this->actingAs($user)` autentica una **sessione**, e in quel caso Sanctum attacca
all'utente un `TransientToken` che consente **ogni** abilità. Un test dei permessi scritto
così passa sempre, anche togliendo il controllo dal middleware. Usare
`Sanctum::actingAs($user, ['site:<id>'])`, e verificare che il test fallisca davvero
rompendo il middleware.

## Monitoraggio dei 404

Un 404 su un sito statico **non lascia traccia in Laravel**: nessuna richiesta lo
raggiunge. La pagina d'errore è quindi un piccolo file PHP sul dominio del sito
(`slimcms-404.php`, generato nel `dist/` come tutto il resto, perché `rsync --delete`
cancellerebbe qualsiasi cosa messa a mano nella document root). Apache lo invoca come
`ErrorDocument` e gli passa l'indirizzo richiesto; lui **annota una riga** in
`<dominio>/private/slimcms-404.jsonl` — fuori dalla document root — e stampa `404.html`.

Nessuna rete e nessuna credenziale sul percorso d'errore: la pagina resta veloce e
funziona anche a backend fermo. `scripts/importa-404.sh` (cron, ogni ora) porta le righe
nel database con `slimcms:importa-404`.

Il file viene **consumato**: rinominato, letto, cancellato. Così non serve ricordare dove
si era arrivati — tenere una posizione di lettura è il modo tipico in cui un monitor muore
dopo una rotazione, restando apparentemente vivo. Il rename avviene *prima* della lettura,
quindi le richieste che arrivano nel frattempo scrivono nel file nuovo e non si perdono.

**Il discriminante è il referrer.** Un 404 con referrer significa che esiste un
collegamento rotto, su questo sito o su quello di qualcun altro. Uno senza referrer è
quasi sempre uno scanner che prova `/wp-admin`. Si registra tutto, ma la vista predefinita
del pannello mostra solo i primi: un elenco pieno di rumore diventa un allarme che si
impara a ignorare — lo stesso ragionamento dei TLD esclusi da
`slimcms:monitora-certificati`.

Dalla riga di un 404 si crea un reindirizzamento con un'azione sola, senza ricopiare
l'indirizzo.

## Slug

`App\Support\Slug::da()` è **l'unico punto** in cui si costruisce uno slug. Era
`Str::slug()` chiamata in nove file — form di pagine, articoli, categorie, tag, tenant,
migrazioni, seeder — ognuno con la propria copia della regola. Gli slug pubblicati sono
indirizzi: se le copie divergono, due contenuti creati in punti diversi finiscono su URL
con regole diverse.

`Str::slug()` da sola non basta per l'italiano. Gli accenti li tratta bene
(`città` → `citta`), ma i caratteri che non riconosce li **elimina** invece di trattarli
come separatori, e le parole si appiccicano:

| Testo | `Str::slug` | `Slug::da` |
|---|---|---|
| `Sant'Angelo` | `santangelo` | `sant-angelo` |
| `Caffè/Tè` | `caffete` | `caffe-te` |
| `SEO/GEO — 2026` | `seogeo-2026` | `seo-geo-2026` |
| `Attività & Servizi` | `attivita-servizi` | `attivita-e-servizi` |

In italiano l'apostrofo è ovunque (dell'arte, l'azienda, un po'): non è un caso di
confine, è il caso normale. Un titolo da cui non resta nulla (ideogrammi, soli emoji) dà
stringa **vuota**: il form la segnala come obbligatoria e chi scrive ne sceglie uno,
meglio che uno slug inventato e illeggibile.

`Slug::regolaUnica()` è la regola di unicità per il sito corrente, usata da tutti e
quattro i form. Senza, due nomi diversi che producono lo stesso slug arrivano al database
e il redattore riceve una pagina di errore SQL invece di un messaggio nel campo. Il
`where('site_id')` va messo a mano: la regola `unique` di Laravel interroga la **tabella**,
non il modello, quindi il global scope di `BelongsToSite` non la tocca — senza, un tag
"novita" di un cliente impedirebbe a tutti gli altri di averne uno.

## Categorie e tag

Sono **due modelli scoped per sito**, `Category` e `Tag`, entrambi con unicità su
`(site_id, slug)`: "performance" è un tag ovvio e lo useranno molti clienti.

I tag erano una colonna JSON di stringhe libere su `posts`. La colonna è stata
**rimossa nella stessa migrazione** che ha creato la tabella, non lasciata lì: con
entrambe, `$post->tags` risolve all'attributo e non alla relazione, `whenLoaded('tags')`
restituisce silenziosamente niente, e l'API continua a servire il vecchio array mentre
il pannello scrive la pivot. Due fonti per lo stesso dato sono lo stesso errore di
`tipo`/`type`.

La migrazione **trasferisce** le etichette esistenti (slugificate, deduplicate per sito)
e la `down()` le riscrive nella colonna: una rollback che perde dati non è una rollback.
Verificata nei due sensi su dati reali. Usa query grezze e prende il `site_id`
dall'articolo, non dal contesto: in una migrazione il contesto tenant non c'è (regola 2).

Il filtro `?tag=` dell'API cerca per **slug**: prima cercava la stringa esatta nel JSON,
quindi "Performance" e "performance" erano due filtri diversi.

## Ruoli e permessi

Quattro ruoli sul pivot `site_user`, in scala: `viewer` < `author` < `editor` < `admin`
(`App\Enums\Ruolo`). Ognuno puo' fare tutto quello che puo' fare quello sotto di lui.
Una matrice per singola azione sarebbe piu' espressiva e sarebbe anche il posto dove le
eccezioni si accumulano finche' nessuno sa piu' cosa vede un autore; quattro gradini si
spiegano in una riga a un cliente.

Il ruolo sta sul pivot e non sull'utente: la stessa persona puo' essere amministratore di
un sito e autore di un altro.

| | viewer | author | editor | admin |
|---|---|---|---|---|
| Vedere pagine, articoli, media, categorie, tag | ✓ | ✓ | ✓ | ✓ |
| Scrivere e modificare pagine e articoli, caricare media | | ✓ | ✓ | ✓ |
| **Pubblicare** | | | ✓ | ✓ |
| Eliminare contenuti e media, gestire categorie e tag | | | ✓ | ✓ |
| Reindirizzamenti e pagine mancanti (anche solo vederli) | | | ✓ | ✓ |
| Gestire i redattori | | | | ✓ |
| Eliminare definitivamente | | | | ✓ |

Prima di queste policy il pannello non ne aveva **nessuna**, e senza policy Filament
consente tutto: un `editor` apriva `/admin/<sito>/users` e si promuoveva ad `admin`. Le
quattro etichette c'erano gia' nel form e non le faceva rispettare niente.

### Le abilita' mancanti non negano: consentono

`get_authorization_response()` (in `filament/filament/src/helpers.php`) cade fino a
`Response::allow()` quando la policy **esiste** ma il metodo per quell'azione **no**. Una
policy scritta a mano che dimentica `deleteAny` lascia aperta la cancellazione di massa e
sembra completa. Per questo le dodici abilita' che Filament interroga stanno scritte una
volta sola in `PolicyDiSito`, e `PolicyRuoliTest` verifica che nessuna sottoclasse ne
perda una e che ogni risorsa del pannello abbia la sua policy.

La firma e' `Authenticatable`, non `User`: il Gate consegna l'utente della guardia attiva,
e da una pagina del control plane arriva un `AdminUser`. Con `User` nella firma era un
errore di tipo — un 500 al posto di un no. Chi amministra la piattaforma entra nel
pannello di un sito impersonando un redattore, non per diritto proprio.

### Pubblicare e' l'unica cosa che esce dal pannello

Una pagina pubblicata accoda una build e finisce online; tutto il resto resta reversibile
dentro l'amministrazione. E' la linea fra autore e redattore, ed e' anche l'unico permesso
che **non** e' un'abilita' di policy, perche' non e' un'azione: e' un valore del campo
`status`.

La guardia sta quindi sul modello (`PubblicazioneRiservata` su `Page` e `Post`), non solo
nel form: una tendina disabilitata non e' un controllo, lo stato di un componente Livewire
arriva dal browser. Il form disabilita le opzioni — non le nasconde, altrimenti una pagina
gia' online si ritroverebbe il campo vuoto e verrebbe ritirata dal sito senza che nessuno
l'abbia chiesto — e il modello le rifiuta di nuovo. `scheduled` conta quanto `published`:
rimandare di un'ora resta pubblicare.

**E vale nei due sensi.** Ritirare dal sito e' lo stesso potere al contrario, e la guardia
guarda quindi la *transizione* (`da online a non online`, e viceversa), non il valore
nuovo. Guardando solo il valore nuovo, portare a bozza una pagina pubblicata passava
indisturbato: sulla home e' peggio che cancellarla, perche' `Page::deleting` impedisce di
cancellarla e niente impediva di ritirarla — e senza `index.html` il gate di deploy blocca
tutte le pubblicazioni successive. Per lo stesso motivo, a chi non puo' pubblicare il form
lascia selezionabile **solo lo stato corrente**: tenere "Bozza" sempre aperta sarebbe il
pulsante per ritirare.

Filament non consulta la policy del modello legato quando apre la finestrella di
`createOptionForm`: creare una categoria o un tag dal form di un articolo scavalcava
`CategoryPolicy` e `TagPolicy`. Il permesso e' chiesto a mano nel form, e un test lo fissa.

Attenzione: `saving` scatta **prima** di `creating`, quindi alla prima creazione `site_id`
e' ancora vuoto e la guardia deve ricadere su `BelongsToSite::currentSiteId()`. Chiedendo
il ruolo su `null` nessuno riusciva piu' a pubblicare una pagina nuova.

Salvare di nuovo un contenuto gia' online non e' pubblicare: un autore deve poter
correggere un refuso in una pagina viva.

### Nessuno concede piu' di quanto ha

Il ruolo scelto nel form e' `dehydrated(false)`: lo scrive a mano `CreateUser`/`EditUser`,
quindi una policy sul modello `User` non lo raggiunge. Passa da
`RuoloCorrente::concedibile()`, che lo abbassa al proprio. Oggi al form ci arriva solo un
amministratore e il limite non morde mai — ed e' il momento giusto per scriverlo, perche'
il giorno in cui un redattore potra' invitare un autore nessuno si ricordera' di
aggiungerlo. Un valore che non e' un ruolo non concede di piu': si ricade su `author`.

Un amministratore non puo' togliere se stesso dal sito: se e' l'ultimo, il sito resta
senza nessuno che possa nominarne un altro e rimediare richiede il control plane.

`RuoloCorrente` e' l'unico punto in cui si risponde a "che ruolo ha chi sta guardando
questo sito": lo chiedono le policy per decidere e i form per non offrire quello che
verrebbe comunque rifiutato. Un pulsante che da' errore e' peggio di un pulsante che non
c'e'.

## Coda di build

Quando un contenuto pubblicato cambia, un observer accoda una rigenerazione in
`build_requests`. Un cron esegue le build in attesa ogni minuto.

Anche una modifica al **sito** accoda. `SiteObserver` elenca le colonne che **non**
toccano il sito pubblicato (id, timestamp, stato di DNS e certificato) e accoda su tutte le
altre. Al positivo — l'elenco di quelle che lo toccano — era un elenco da aggiornare a ogni
colonna nuova, e non e' successo: `footer_config`, `layout_config`, `og_config` e
`favicon_initials` sono arrivate dopo e non ci sono mai entrate, quindi configurare il
footer o la testata dal pannello non rigenerava niente e il sito restava com'era senza
dirlo. Una build di troppo si nota e costa un minuto; una build che non parte non si nota
affatto.

**Non è un worker Laravel, ed è una deviazione dalle specifiche §7.1 dovuta all'ambiente:**
php.ini qui disabilita `pcntl_*`, quindi `queue:work` in modalità daemon e **Horizon non
partono affatto** (`Call to undefined function pcntl_signal`). Il comando
`slimcms:build-queue` fa lo stesso lavoro in modo idempotente, protetto da `flock` e da
`lockForUpdate` in transazione, quindi girare ogni minuto è sicuro anche con passate
sovrapposte. Se un giorno `pcntl` venisse abilitato, passare a un job vero è un refactor
locale: la logica di debounce sta già in `BuildQueue`.

| Comportamento | Dove |
|---|---|
| Debounce 45s, tetto massimo 300s | `BuildQueue::accoda()` |
| Una build `full` assorbe le `incremental` in attesa | idem |
| `site.created` salta il debounce ed ha priorità | idem + `orderByRaw` nel runner |
| Retry con backoff 1/4/9 minuti, max 3 tentativi | `RunBuildQueue::esegui()` |
| Alert per build ferme oltre 5 minuti | `BuildRequest::inRitardo()` |

Le bozze che restano bozze **non** accodano nulla: una pagina mai pubblicata non esiste nel
sito statico, e rigenerarlo a ogni salvataggio automatico dell'editor sarebbe lavoro sprecato.

L'observer prende il sito da `$model->site_id`, **non** da `app('currentSite')`: in coda o
in console quel binding può non esserci, e la build finirebbe sul sito sbagliato.

Comandi utili: `php artisan slimcms:build-queue --site=<dominio>` forza una build completa
ignorando il debounce; `GET /api/sites/{site}/builds` mostra lo stato.

## Domini custom, SSL e routing edge

**Il provisioning dei certificati richiede root e non è automatizzabile da qui.** Su questa
macchina i vhost li gestisce HestiaCP, che ha già Let's Encrypt col proprio cron di rinnovo;
la sua CLI però risponde `Permission denied` all'utente normale. `slimcms:provisiona-dominio`
verifica tutto il verificabile e poi **stampa i comandi esatti** da eseguire con sudo,
invece di fallire in modo opaco.

L'ordine non è negoziabile: Let's Encrypt valida via HTTP, quindi **il DNS deve già puntare
qui prima** di chiedere il certificato. Il comando si rifiuta di procedere senza DNS
corretto (`--forza` per scavalcare): un tentativo fallito consuma quota verso i limiti di
emissione, che sono stretti.

`slimcms:monitora-certificati` gira ogni giorno alle 6:15 e verifica DNS e scadenza di ogni
sito, con alert email sotto i 21 giorni. **Il rinnovo lo fa Hestia; questo verifica che
l'abbia fatto** — è la differenza fra avere il rinnovo automatico e sapere che funziona
(specifiche §11). I TLD riservati (`.test`, `.local`, …) sono esclusi: sono i siti demo, e
un alert che si impara a ignorare è peggio di nessun alert.

### Routing edge (§7.2)

`slimcms:mappa-routing` genera la mappa dominio → sito in due formati: `routing.json` per
un edge programmabile (Cloudflare Workers + KV) e `slimcms-map.conf`, una `map` nginx per il
server attuale. In entrambi il **default è vuoto di proposito**: un dominio sconosciuto deve
dare errore, non finire per sbaglio sul sito di un altro cliente.

La mappa si rigenera **solo su eventi strutturali** — sito creato, dominio cambiato, sito
cancellato — non a ogni pubblicazione: è il punto della §7.2, che la risoluzione del dominio
non stia nel percorso di lettura. `SiteObserver` la richiama da solo.

`GET /api/routing-map` la espone all'edge e richiede un token **di piattaforma** (`sites:*`):
è l'elenco di tutti i clienti, non il contenuto di uno, quindi un token legato a un singolo
sito riceve 403.

Nota: gli alias middleware `abilities` e `ability` di Sanctum **non sono registrati da
Laravel 12**. Sono in `bootstrap/app.php`; senza, una rotta che li usa dà 500 invece di
applicare il controllo — un errore di sicurezza travestito da errore generico.

## Pubblicazione del frontend

**Usa sempre `scripts/deploy-frontend.sh`.** Non lanciare `npm run build` seguito da
`rsync` a mano.

Il 2026-09-04 il sito è andato offline così: la build era dentro una pipe con `grep`
(che maschera l'exit code), una riga "Completed" di vite è sembrata un successo, e il
`rsync --delete` è girato in un comando separato senza verificare l'output. Astro si era
comportato correttamente — exit 1 e nessun `index.html` — l'errore era nella procedura.

Lo script tiene build, verifica e pubblicazione in un blocco solo, e `rsync --delete` non
parte se anche una sola verifica fallisce: `index.html` presente e sopra una dimensione
minima, frammenti di contenuto attesi realmente nell'HTML, sitemap con almeno una URL,
e infine `HTTP 200` più controllo del contenuto sul sito pubblicato.

`--dry-run` fa build e verifica senza pubblicare.

**Node va risolto esplicitamente, non preso dal `PATH`.** Il cron gira con un `PATH`
minimale e trova `/usr/bin/node`, che qui è la **v12**; Astro 7 ne richiede almeno la 22.12
e muore con `SyntaxError: Unexpected token '.'` — l'optional chaining che quel Node non sa
leggere. È un errore che non dice nulla sulla causa reale, e ha fatto fallire tre volte le
build accodate dal pannello mentre le stesse build lanciate a mano riuscivano, perché la
shell interattiva ha nvm nel `PATH`. Lo script ora cerca la versione di nvm, verifica che
sia almeno la 22 e si ferma con un messaggio esplicito se non lo è.

**Le immagini dei contenuti diventano file del sito.** L'API le espone con URL
assoluti verso il backend; lasciarli così significherebbe che ogni foto del sito
pubblico viene servita da `manage.slimcms.it`, cioè che il sito statico torna a
dipendere da Laravel per essere leggibile — l'opposto della §7. La build le scarica
(`src/pages/media/[...percorso].ts`, stesso schema delle immagini Open Graph) e le
deposita in `/media/<id>/<nome>`; `percorsoMedia()` in `src/lib/api.ts` è la sola
funzione che decide quel percorso, così la rotta che scrive i file e le pagine che li
citano non possono divergere. Il gate di deploy verifica che ogni immagine citata
dall'HTML esista davvero in `dist/`: un download fallito lascerebbe una pagina valida,
della sua brava dimensione, con dei riquadri rotti al posto delle foto.

La sitemap è generata a ogni build dai dati dell'API, quindi si aggiorna da sola a ogni
pubblicazione. Le URL portano **sempre lo slash finale**: Astro genera `<slug>/index.html`,
quindi il server risponde 200 solo su quella forma e 301 sull'altra. Il gate di deploy
verifica che ogni URL elencata risponda 200 senza redirect — una sitemap che elenca URL che
redirigono fa pagare un salto in più a ogni passaggio del crawler, ed è un difetto che non
si vede guardando il sito.

Attenzione: `php artisan test` azzera il database **e i token API**. Dopo i test servono
`php artisan db:seed` e un nuovo token per la build:
`php artisan slimcms:build-token <email> --site=slimcms.it`.

## Contratto API fra Laravel e Astro

Due famiglie di endpoint con modelli di autorizzazione **diversi**. Non confonderli.

| Famiglia | Chi chiama | Autorizzazione | Come si risolve il sito |
|---|---|---|---|
| `/api/sites/{site}/...` | worker di build Astro | token Sanctum con ability `site:<id>` (`site.token`) | dalla URL, `Site::getRouteKeyName()` = `domain` |
| `/api/public/...` | browser del visitatore | nessuna, rate limited | dall'`Host` (`site.domain`) |

Il token è legato a **un** sito. `sites:*` esiste per il worker di piattaforma ed è una
credenziale critica: chi lo possiede legge i contenuti di ogni cliente. Si emette con
`php artisan slimcms:build-token <email> --site=<dominio>`.

I controller **non** filtrano per `site_id` a mano: lo fa il global scope, dato che il
middleware ha già fissato il sito corrente. Un `where` esplicito sarebbe ridondante e
darebbe la falsa impressione che senza di esso la query sia aperta.

Il frontend Astro chiama l'API **solo in fase di build** (`src/lib/api.ts`). Se vedi quel
modulo importato da un componente con `client:*`, è un errore: il visitatore riceve HTML
statico e non deve mai toccare il backend.

## Due piani, due identita', due guardie

| | Control plane | Data plane |
|---|---|---|
| URL | `/manage` (→ `manage.slimcms.it`) | `/admin` (→ `<dominio>/admin`) |
| Modello utente | `AdminUser` (tabella `admin_users`) | `User` (tabella `users`) |
| Guardia | `manage` | `web` |
| Colore | rosso ruggine | verde pino |
| MFA | **obbligatoria per tutti** | obbligatoria per chi è `admin` su un sito |

Le due tabelle utente sono separate **di proposito**: non è un ruolo in più su `users`.
Con una tabella sola, un errore in una policy o una query dimenticata trasformerebbe un
redattore in super-admin. Separarle rende quell'errore impossibile per costruzione.

La stessa persona che amministra la piattaforma e redige contenuti ha **due account
distinti**, anche con la stessa email.

Verificato: un redattore su `/manage/tenants` finisce su `/manage/login`; un amministratore
di piattaforma su `/admin/.../pages` finisce su `/admin/login`.

### MFA: perché un middleware e non una closure

Filament accetta `multiFactorAuthentication(..., isRequired: Closure)`, ma quella closure è
valutata **al boot**, quando registra rotte e middleware (`HasComponents.php:594`), non a
ogni richiesta: a quel punto non c'è nessun utente autenticato, quindi una closure
per-utente restituirebbe sempre `false`, la pagina di setup non verrebbe registrata e chi
ne ha bisogno finirebbe su una rotta inesistente.

Quindi il pannello dichiara `isRequired: true` (così rotte e middleware esistono) e
l'esenzione per i ruoli non amministrativi la applica `RichiediMfaSoloAgliAdmin`, che gira
per richiesta.

Lo stesso middleware esenta anche le sessioni **aperte impersonando dal control plane**:
lì l'MFA è obbligatoria per tutti, quindi il secondo fattore è già stato dimostrato pochi
secondi prima dalla stessa persona. Chiederlo di nuovo non aggiungerebbe sicurezza e
obbligherebbe a iscrivere due dispositivi per lo stesso essere umano. L'esenzione si
verifica sul **record** di impersonazione (l'amministratore aveva davvero l'MFA attiva?),
non sulla sola presenza della chiave di sessione: così poggia su un fatto registrato e
verificabile a posteriori. Segreto TOTP e codici di recupero sono **cifrati a riposo**
(`'encrypted'` cast): chi legge il database non deve poter rigenerare i codici di nessuno.

## Il contratto fra pannello e sito pubblico

`backend/tests/Feature/ContrattoBlocchiTest.php` legge l'elenco dei blocchi dal form
Filament — che ne è la fonte — e pretende che ognuno sia reso da
`frontend/src/components/blocchi/Blocchi.astro`, e viceversa. Poi fa fare a ogni tipo
il giro intero: salvataggio dal pannello, rilettura nel pannello, uscita dall'API con
le immagini risolte.

Attraversa i due linguaggi di proposito. **Quasi tutti i guasti di questo progetto sono
nati sulla stessa giuntura**: due metà scritte in momenti diversi, ciascuna verificata
contro *l'idea* dell'altra invece che contro l'altra — i blocchi salvati piatti mentre
il form li voleva annidati, `capacita` usato dal contenuto e assente dal form, la
galleria che non teneva alcun riferimento alle immagini. Due metà sbagliate nello
stesso modo passano tutti i test scritti per lato.

Un tipo di blocco nuovo va aggiunto a `datiDiProva()`: senza, il test fallisce con un
messaggio esplicito invece di saltarlo in silenzio.

## Convenzioni di codice

- Segui le convenzioni Laravel standard; non introdurre astrazioni non richieste.
- Modelli in `app/Models/`, trait condivisi in `app/Models/Concerns/`.
- Enum PHP nativi per gli stati (`status`, `role`), non stringhe libere.
- Campi JSON (`blocks`, `theme`, `seo`, `key_facts`) sempre con `casts` espliciti.
- Ogni nuova migrazione che crea una tabella di contenuto include la colonna di scoping
  **e** un indice su di essa.
- Commenti e messaggi utente in italiano, nomi di codice in inglese.

## SEO / GEO / AEO

È il cuore differenziante del prodotto, non un dettaglio finale. Ogni `Page` e `Post` porta
campi SEO tradizionali, GEO (`structured_summary`, `key_facts`, `source_attribution`) e AEO
(`faq_block`, `direct_answer`, tipo Schema.org). Il JSON-LD lo genera Astro automaticamente
dai campi API, senza intervento del redattore. Dettaglio completo: sezione 6 della specifica.

## Ordine di lavoro (MVP)

1. Scaffold `backend/` (Laravel 12 + Filament 5 + stancl/tenancy) e `frontend/` (Astro)
2. Modelli e migrazioni: `Tenant`, `Plan`, `AdminUser`, `Site`, `Page`, `User`
3. Adattare `docs/BelongsToTenant.php` → `backend/app/Models/Concerns/BelongsToSite.php`
   (rinominato, colonna `site_id`, risoluzione via contesto stancl + binding sito;
   il tipo di ritorno `?int` va rivisto perché il livello tenant usa chiavi stringa)
4. **Riscrivere** `docs/TenantScopeTest.php` → `backend/tests/Unit/TenantScopeTest.php`
   sulla mappa colonna → trait descritta sopra. Il file consegnato assume un trait unico
   e `Site` escluso dallo scoping: entrambe le assunzioni non valgono più. Non è un
   semplice spostamento di file.
5. Pannello Filament data plane con page builder a blocchi
6. API JSON + Sanctum, consumata da Astro in build
7. Campi SEO base end-to-end, dal form Filament al JSON-LD renderizzato

Non saltare i punti 3 e 4: sono la fondazione di sicurezza su cui poggia tutto il resto.
