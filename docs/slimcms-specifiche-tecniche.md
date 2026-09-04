# SlimCMS — Specifiche Tecniche

**Versione documento:** 0.2
**Data:** Settembre 2026
**Stack:** Laravel + Filament (backend/admin) + Astro (frontend pubblico)

---

## 1. Panoramica del progetto

SlimCMS è una piattaforma CMS multitenant pensata per sostituire WordPress nella gestione di numerosi mini siti aziendali/blog, con un pannello di amministrazione centralizzato e un motore di rendering pubblico ottimizzato per performance e per l'indicizzazione da parte di motori di ricerca tradizionali (SEO), motori generativi (GEO) e sistemi di risposta AI (AEO).

### Domini coinvolti

| Dominio | Funzione |
|---|---|
| `www.slimcms.it` | Sito vetrina del prodotto SlimCMS (marketing, pricing, onboarding) |
| `manage.slimcms.it` | Console di gestione tenant (creazione siti, utenti, billing, monitoraggio) |
| `<url-tenant>/admin` | Pannello di amministrazione contenuti di ogni singolo sito gestito |

---

## 2. Architettura generale

```
+-----------------------------------------------------------+
|                     manage.slimcms.it                     |
|         Filament "control plane" (super-admin)            |
|   Gestione tenant, provisioning siti, utenti globali      |
+---------------------------+---------------------------------+
                            | crea/configura
                            v
+-----------------------------------------------------------+
|              Laravel "data plane" (multitenant)            |
|         Un'unica app Laravel, DB MySQL condiviso           |
|         Isolamento per tenant tramite package dedicato     |
|         Espone API REST/JSON per ogni sito                 |
+---------------------------+---------------------------------+
                            | fetch dati in build/SSR
                            v
+-----------------------------------------------------------+
|                  Astro (frontend pubblico)                 |
|     Rendering per dominio, risoluzione tenant via Host      |
|     output: "hybrid", statico dove possibile,               |
|     server-rendered dove serve dinamismo                    |
+-----------------------------------------------------------+
```

### Scelta architetturale: backend disaccoppiato dal frontend

Laravel non renderizza le pagine pubbliche (niente Blade lato pubblico): resta un backend puro che espone API JSON. Questo permette ad Astro di generare pagine statiche o server-rendered ottimizzate, mantenendo il vantaggio prestazionale che ci ha spinto a scegliere Astro fin dall'inizio, senza rinunciare alla maturità di Laravel/Filament su admin, permessi e multitenancy.

### Scelta architetturale: control plane separato dal data plane

`manage.slimcms.it` non è "un altro tenant": è un pannello Filament distinto con risorse dedicate (`Tenant`, `AdminUser`, `Plan`, `Billing`) che parla con il data plane solo per operazioni di provisioning (creazione/sospensione di un tenant). Questo separa nettamente i permessi: chi amministra un mini sito cliente non deve mai poter vedere o toccare dati di altri tenant o la configurazione della piattaforma stessa.

---

## 3. Stack tecnico

| Componente | Scelta | Note |
|---|---|---|
| Backend/CMS | Laravel (ultima LTS) + Filament | Filament fornisce admin panel, CRUD, media library, ruoli/permessi pronti |
| Frontend pubblico | Astro (output `hybrid`) | Isole React dove serve interattività (form, filtri) |
| Database | MySQL | Supporto nativo Laravel/Eloquent, nessun compromesso |
| Multitenancy | Package dedicato (es. `stancl/tenancy` o `spatie/laravel-multitenancy`) | Da valutare in base al modello (single database con tenant_id vs database per tenant) |
| Autenticazione | Laravel Sanctum (per le API verso Astro) + Filament Auth (per l'admin) | Ruoli separati per control plane e per singolo tenant |
| Storage media | S3-compatible (es. Cloudflare R2), tramite Laravel Filesystem | Path per tenant: `/tenants/<tenant-id>/media/` |
| Hosting frontend | Vercel/Netlify/Cloudflare Pages | Ambienti separati dev/staging/prod (vedi sezione 5) |
| Hosting backend | VPS o server gestito con PHP/MySQL | Nessun vincolo particolare, stack standard Laravel |
| Coda di build | Laravel Queue + Redis | Gestisce la rigenerazione statica per-tenant in modo asincrono, vedi sezione 7.1 |

### 3.1 Nota su MySQL e Laravel

A differenza di Payload, Laravel/Eloquent supporta MySQL come database di prima classe fin dalle origini del framework: nessun adapter di terze parti, nessun compromesso, piena compatibilità con migrazioni, seeding, query builder e con l'ecosistema Filament. Questo elimina completamente il problema affrontato nella versione precedente di questo documento.

### 3.2 Scelta del modello di multitenancy

Due strade possibili con `stancl/tenancy` (il package più maturo per Laravel):

- **Single database, tenant scoping via `tenant_id`**: più semplice da gestire operativamente (un solo DB da fare backup, un solo schema da migrare), isolamento garantito da global scope Eloquent applicati automaticamente su ogni query. Consigliato per iniziare.
- **Database per tenant**: isolamento fisico più forte (nessun rischio di query che attraversano tenant per errore), ma più complesso da orchestrare su scala con molti mini siti (ogni migrazione va eseguita su ogni DB tenant). Da valutare se in futuro emergono requisiti di compliance più stringenti per singoli clienti enterprise.

**Raccomandazione:** partire con single database e tenant scoping, che è anche il pattern più vicino a quanto avevamo previsto in origine, con la possibilità di migrare a database-per-tenant in un secondo momento se necessario.

---

## 4. Ambienti: dev e prod separati

| Ambiente | Dominio frontend | Dominio admin | Database | Note |
|---|---|---|---|---|
| Development | `dev.slimcms.it` / locale | `manage-dev.slimcms.it` | Istanza MySQL separata (dati seed) | Branch `develop`, deploy automatico a ogni push |
| Staging (opzionale) | `staging.slimcms.it` | `manage-staging.slimcms.it` | Copia periodica del DB prod (anonimizzata) | Per test pre-rilascio con dati realistici |
| Production | `www.slimcms.it` + domini tenant reali | `manage.slimcms.it` | DB produzione MySQL | Deploy solo da branch `main`, con approvazione manuale |

### Pratiche consigliate

- Variabili d'ambiente separate per ogni ambiente (file `.env` per ambiente, mai committati nel repo)
- Migrazioni Laravel versionate, eseguite automaticamente in CI/CD prima del deploy (`php artisan migrate --force` in produzione, sempre con backup precedente)
- Seed di dati demo disponibile solo in dev (`php artisan db:seed`), per permettere test rapidi senza toccare dati reali
- Backup automatico giornaliero del DB di produzione, con retention di almeno 30 giorni
- Code di lavoro (queue) separate per ambiente, per non mischiare job di test con job reali (invii email, generazione sitemap, ecc.)

---

## 5. Data model (risorse Filament / modelli Eloquent)

### Control plane (`manage.slimcms.it`)

**`Tenant`**
- `name`, `slug` (univoco)
- `custom_domain` (opzionale, dominio custom del cliente)
- `status` (enum: `active`, `suspended`, `trial`)
- `plan_id` (relazione a `Plan`)
- `created_at`

**`AdminUser`**
- `email`, `password` (gestiti da Laravel Auth)
- `role` (enum: `super-admin`, `support`)
- Relazione many-to-many con `Tenant`, per supporto scoped a specifici clienti

**`Plan`**
- `name`, `price_monthly`, `max_sites`, `max_storage_gb`, `features_included` (JSON)

### Data plane (app Laravel che serve i siti, con tenant scoping)

**`Site`** (uno o più per tenant, se un cliente può avere più mini siti)
- `tenant_id` (chiave di scoping, applicata via global scope automatico)
- `domain` (dominio primario del sito)
- `name`, `logo_path`, `favicon_path`
- `theme` (JSON: colori, font, configurazione visuale)
- `seo_defaults` (JSON o relazione dedicata, vedi sezione 6)

**`Page`**
- `site_id` (obbligatorio su ogni query, applicato via global scope)
- `title`, `slug`
- `blocks` (JSON: Hero, TestoRicco, Galleria, CTA, FAQ, gestiti in Filament con un builder a blocchi)
- `seo` (gruppo campi SEO/GEO/AEO, vedi sezione 6)
- `status` (enum: `draft`, `published`, `scheduled`)
- `publish_at` (data programmata)

**`Post`** (blog)
- Stessa struttura di `Page` con in più: `author_id`, `categories`, `tags`, `excerpt`, `featured_image_path`

**`Media`**
- Gestita da Filament Media Library (o Spatie Media Library, standard de facto in Laravel), storage isolato per tenant

**`User`** (utenti redattori/editor del singolo sito)
- `role` (enum: `admin`, `editor`, `author`, `viewer`)
- Relazione many-to-many con `Site`, per gestire redattori che lavorano su più mini siti dello stesso cliente

### Isolamento multitenant

L'isolamento va implementato tramite **Global Scope Eloquent** applicati automaticamente a `Page`, `Post`, `Media` e a ogni modello scoped per tenant: ogni query costruita nel codice applica in automatico il filtro `tenant_id`/`site_id` corrente, senza che lo sviluppatore debba ricordarsene manualmente a ogni query. Il pacchetto `stancl/tenancy` gestisce questo pattern in modo nativo. Nessuna richiesta deve poter leggere dati di un tenant diverso da quello autenticato, nemmeno per errore di query lato Astro: il controllo va sempre fatto lato Laravel, mai delegato al frontend.

---

## 6. Focus SEO, GEO, AEO

Questo è il cuore differenziante di SlimCMS rispetto a un CMS generico. Il campo `seo` presente su `Page` e `Post` deve includere:

### SEO tradizionale
- `meta_title`, `meta_description` (con contatore caratteri e preview SERP direttamente nel form Filament)
- `canonical_url`
- `noindex` / `nofollow` (toggle)
- `og_image`, `og_title`, `og_description` (Open Graph per social)
- `sitemap_priority`, `sitemap_change_freq`
- Generazione automatica di `sitemap.xml` e `robots.txt` per ogni sito (endpoint Laravel dedicato, consumato da Astro in build o on-demand)

### GEO (Generative Engine Optimization)
- `structured_summary`: campo testuale breve, scritto in linguaggio naturale, pensato specificamente per essere "digerito" bene da motori generativi (Perplexity, Google AI Overview, ChatGPT Search), sintesi chiara del contenuto della pagina in 2-3 frasi
- `key_facts`: campo JSON array di affermazioni fattuali chiave della pagina (utile perché i motori generativi tendono a citare frasi dichiarative isolabili)
- `source_attribution`: dati strutturati su autore/data pubblicazione/data aggiornamento, per dare segnali di autorevolezza e freschezza ai crawler AI

### AEO (Answer Engine Optimization)
- `faq_block`: blocco FAQ strutturato (builder Filament dedicato) con markup Schema.org `FAQPage` generato automaticamente
- `direct_answer`: campo opzionale per pagine che rispondono a una domanda specifica ("Cos'e...", "Come si fa..."), con risposta diretta pensata per essere estratta come featured snippet o risposta vocale
- Markup Schema.org automatico in base al tipo di contenuto: `Article`, `Organization`, `LocalBusiness`, `Product`, `HowTo` (selezionabile dall'editor in Filament in base al tipo di pagina)

### Implementazione tecnica lato Astro
- Ogni pagina genera automaticamente JSON-LD (`Article`, `FAQPage`, `BreadcrumbList`, `Organization`) a partire dai campi ricevuti dalle API Laravel, senza intervento manuale del redattore
- Un "punteggio SEO/GEO/AEO" in tempo reale nel form Filament (widget custom), che valuta: lunghezza title/description, presenza di `structured_summary`, presenza di almeno un `key_fact`, presenza di markup FAQ dove pertinente
- Prerendering completo (statico) per tutte le pagine di contenuto pubblicate, per garantire tempi di risposta minimi ai crawler (fattore SEO diretto e prerequisito per una buona GEO, dato che i motori generativi privilegiano contenuto facilmente accessibile e ben strutturato)

---

## 7. Strategia di rendering: siti statici con build incrementale per-tenant

La risoluzione del tenant non avviene più a runtime ad ogni richiesta, ma viene spostata al momento della pubblicazione dei contenuti. Questo elimina quasi del tutto il round-trip verso Laravel per i visitatori, e riduce drasticamente il carico sul backend con centinaia di mini siti attivi.

### Flusso di pubblicazione

```
1. Un redattore pubblica/modifica una pagina in Filament
2. Laravel invia un webhook al servizio di build, con:
   site_id, tipo di evento (page.published, page.updated, page.deleted)
3. Il webhook accoda un job nella "coda di build" (vedi sezione 7.1),
   NON esegue il rebuild in modo sincrono
4. Il worker della coda genera staticamente SOLO le pagine del sito
   interessato (build incrementale, non rebuild globale della piattaforma)
5. L'output statico viene pubblicato sotto il dominio corretto tramite
   il routing a livello edge/CDN (vedi sezione 7.2)
6. Le richieste dei visitatori vengono servite direttamente dalla CDN,
   senza toccare Laravel
```

### 7.1 Coda di build

Componente nuovo rispetto alle versioni precedenti del documento, necessario per non rigenerare i siti in modo sincrono e bloccante durante la pubblicazione.

| Aspetto | Scelta |
|---|---|
| Sistema di code | Laravel Queue (driver Redis in produzione, driver database in dev) |
| Job dedicato | `BuildSitePagesJob`, riceve `site_id` e l'elenco delle pagine da rigenerare |
| Trigger | Webhook Laravel scatenato da eventi Filament (`Page::saved`, `Page::published`, `Page::deleted`) tramite Observer dedicato |
| Granularità | Per singola pagina quando possibile (una modifica a un articolo non deve rigenerare l'intero sito), rebuild completo del sito solo per modifiche strutturali (cambio tema, nuova navigazione) |
| Debounce | Se arrivano più modifiche ravvicinate sullo stesso sito (es. un redattore che salva più volte in pochi minuti), le build vengono raggruppate per evitare rebuild ridondanti (es. finestra di debounce di 30-60 secondi prima di eseguire il job) |
| Priorità | Coda dedicata e prioritaria per la pubblicazione di un sito nuovo (primo deploy di un tenant), separata dalla coda di aggiornamenti ordinari, per garantire tempi di attivazione rapidi ai nuovi clienti |
| Monitoraggio | Dashboard code (es. Laravel Horizon) per individuare rapidamente build fallite o in coda da troppo tempo, con alert se un job resta in coda oltre una soglia (es. 5 minuti) |
| Retry | Job con retry automatico e backoff in caso di fallimento (es. servizio di build temporaneamente non raggiungibile), con notifica se il retry esaurisce i tentativi |

### 7.2 Routing a livello edge/CDN

Il "quale dominio serve quale sito" diventa una configurazione di routing risolta una volta, non ad ogni richiesta:

- Mappa dominio → sito mantenuta a livello edge (es. Cloudflare Workers + KV storage, o un progetto/deploy dedicato per sito su Vercel/Netlify se il volume di siti lo consente)
- La mappa viene aggiornata solo in occasione di eventi strutturali (nuovo tenant creato, dominio custom aggiunto/rimosso), non ad ogni pageview
- Nessuna chiamata a Laravel nel percorso di lettura pubblico: il backend entra in gioco solo in fase di pubblicazione (scrittura) e per le poche funzionalità che restano dinamiche (vedi sotto)

### 7.3 Cosa resta dinamico

Una minoranza di funzionalità continua a chiamare l'API Laravel al volo, perché richiede dati in tempo reale o interazione dell'utente: ricerca interna del sito, form di contatto, eventuali aree autenticate. Va isolata chiaramente dal resto (es. componenti Astro con `client:load` che chiamano l'API solo per quella specifica interazione), così il grosso del traffico di lettura resta completamente statico.

Per i domini custom dei clienti (es. `www.clientexyz.it` che punta a un mini sito SlimCMS), serve un sistema di provisioning automatico di certificati SSL tramite **Let's Encrypt**, con rinnovo automatico (es. via `certbot` con hook di deploy, o l'integrazione ACME già presente in molti pannelli server come Caddy o Traefik, che gestiscono l'intero ciclo di vita del certificato senza intervento manuale) collegato alla creazione del tenant in `manage.slimcms.it`.

---

## 8. Autenticazione e accesso `/admin`

Ogni sito gestito ha il proprio punto di accesso `<dominio-sito>/admin`, ma **non è un'installazione Filament separata per sito**: è la stessa app Laravel/Filament del data plane, con:

- Login che identifica l'utente e i siti a cui ha accesso
- Se l'utente ha accesso a un solo sito, atterra direttamente sulla dashboard di quel sito (Filament supporta pannelli/tenant multipli nativamente tramite il concetto di "tenancy" integrato nel framework)
- Se ha accesso a più siti dello stesso cliente, un selettore di sito prima della dashboard (funzionalità nativa di Filament multi-tenancy)
- Nessun utente "editor" può in nessun caso vedere la lista di altri tenant o accedere a `manage.slimcms.it`

---

## 9. API tra Laravel e Astro

Laravel espone un set di endpoint JSON pensati specificamente per il consumo da parte del sistema di build Astro (chiamati in fase di build/pubblicazione, non ad ogni richiesta pubblica) e per le poche funzionalità dinamiche residue:

- `GET /api/sites/{site}/pages/{slug}` — contenuto pagina, inclusi blocchi e campi SEO/GEO/AEO, letto dal worker di build
- `GET /api/sites/{site}/posts` — lista articoli blog, con paginazione e filtri, letto dal worker di build
- `GET /api/sites/{site}/sitemap` — dati per generazione sitemap statica
- `POST /api/webhooks/site-updated` — webhook interno (Filament → coda di build) che notifica quali pagine rigenerare, non esposto pubblicamente
- `GET /api/sites/{site}/search?q=` — ricerca interna, unica chiamata realmente invocata a runtime dal visitatore finale
- `POST /api/sites/{site}/contact` — invio form di contatto, invocata a runtime
- Autenticazione tramite Laravel Sanctum (token API), con rate limiting sugli endpoint dinamici residui (ricerca, form), dato che sono gli unici raggiungibili direttamente dal pubblico

---

## 10. Prossimi passi (MVP)

1. Setup progetto Laravel + Filament, installazione `stancl/tenancy` con modello single-database
2. Repo separati o monorepo: `slimcms-backend` (Laravel) e `slimcms-frontend` (Astro), con contratto API come punto di collegamento
3. Modelli base: `Site`, `Page`, `Media`, `User` con global scope multitenant funzionante e testato
4. Un tipo di pagina semplice (contenuto a blocchi) renderizzato su Astro con dominio dinamico, consumando l'API Laravel
5. Campi SEO base (`meta_title`, `meta_description`, JSON-LD `Article`) end-to-end, dal form Filament al render Astro
6. MFA tramite app authenticator (TOTP, es. Google Authenticator/Authy) sul login Filament, sia per `manage.slimcms.it` sia per l'accesso `/admin` dei singoli siti; package consigliato `pragmarx/google2fa` o l'integrazione MFA nativa di Filament, con possibilità di renderlo obbligatorio per i ruoli `super-admin` e `admin`
7. Primo sito pilota reale migrato, per validare il flusso completo prima di costruire `manage.slimcms.it`

---

## 11. Rischi aperti da monitorare

- Corretta applicazione dei global scope multitenant su ogni nuovo modello che verrà aggiunto in futuro (rischio umano: uno sviluppatore che dimentica lo scope su una nuova feature)
- Backlog della coda di build in caso di picco di pubblicazioni simultanee (es. molti redattori che pubblicano insieme in un orario di punta): va dimensionato il numero di worker e monitorato il tempo medio di attesa in coda, con alert se supera una soglia accettabile
- Provisioning automatico SSL per domini custom via Let's Encrypt/ACME (`certbot`, o gestione nativa Caddy/Traefik): il rinnovo automatico va monitorato con un alert se un certificato non si rinnova correttamente, per non ritrovarsi un sito cliente offline per certificato scaduto
- Governance dei permessi tra control plane e data plane, da testare con audit di sicurezza prima del primo cliente reale in produzione
- Sincronizzazione tra cache Astro (contenuti fetchati) e aggiornamenti pubblicati in Filament: serve una strategia di invalidazione (webhook da Laravel ad Astro per rebuild/revalidate on-demand)
- Sessione MFA nel contesto multi-tenancy di Filament: va verificato che il passaggio da un sito all'altro per utenti con accesso a più tenant non richieda una nuova verifica TOTP a ogni switch, per non rendere scomoda l'esperienza di chi gestisce più mini siti
- Cache dei tag Open Graph lato piattaforme social (Facebook, LinkedIn, X): quando una pagina viene ripubblicata con OG image/title/description aggiornati, i social mantengono comunque in cache l'anteprima precedente finché non viene forzato un refresh manuale tramite i rispettivi debugger tool (Facebook Sharing Debugger, LinkedIn Post Inspector); da prevedere un promemoria per i redattori o un link diretto a questi strumenti nel pannello Filament dopo ogni pubblicazione
