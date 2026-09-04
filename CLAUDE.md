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

## Coda di build

Quando un contenuto pubblicato cambia, un observer accoda una rigenerazione in
`build_requests`. Un cron esegue le build in attesa ogni minuto.

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
