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
| Database | MariaDB 10.6.23 **remota su `10.0.0.3`** | db `claudio_slimcms`, utente `claudio_slimcms`. NON è il MariaDB locale |
| Queue / cache | Redis 6.0 (locale, attivo) | driver `database` accettabile solo in test |
| Frontend | Astro 7.3.1, output `static` | Node 24.13. `hybrid` (citato nelle specifiche) e' stato RIMOSSO in Astro 7: `static` e' il default e fa la stessa cosa |
| Auth API | Laravel Sanctum ^4 | token per il worker di build Astro |

Docker **non** è disponibile su questa macchina: tutto gira su servizi di sistema.

Il database è **remoto** (`10.0.0.3`), non locale: sulla macchina di sviluppo gira anche una
MariaDB locale che **non c'entra nulla** con il progetto — non puntarci mai. Redis invece è
quello locale. L'utente DB ha `ALL PRIVILEGES` solo su `claudio_slimcms`.*: non può creare
altri database, quindi il database di test va richiesto a chi amministra `10.0.0.3`.

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
php artisan serve                     # dev server
php artisan migrate                   # migrazioni
php artisan test                      # suite completa (richiede il db claudio_slimcms_test)
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
