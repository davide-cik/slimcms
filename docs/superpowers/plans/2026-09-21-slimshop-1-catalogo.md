# SlimShop, passo 1 — Catalogo: piano di implementazione

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Il control plane accende il negozio di un sito; nel pannello del sito si creano prodotti con prezzo, scorte e immagini; il sito statico pubblica una pagina per prodotto con prezzo, disponibilità e JSON-LD `Product`. Ancora niente carrello e niente pagamento.

**Architecture:** Nuovo modello `Prodotto`, scoped per `site_id` come `Page`/`Post`, con la stessa pipeline: risorsa Filament → API di build `/api/sites/{site}/prodotti` → rotta unica `[...percorso].astro` → HTML statico. L'interruttore `sites.shop_attivo` si scrive solo dal control plane. A negozio spento l'API restituisce un elenco vuoto, quindi la build non genera pagine prodotto e i siti senza negozio non cambiano.

**Tech Stack:** Laravel 12, Filament 5, spatie/laravel-medialibrary, Astro 7 (output static), PHPUnit.

**Spec:** `docs/superpowers/specs/2026-09-21-slimshop-design.md` (§1, §2 `prodotti`, §3 pagine statiche, §7 riga Prodotti, §10 passo 1).

## Global Constraints

- Eseguire i comandi del backend da `backend/`, quelli del frontend da `frontend/`, mai dalla radice.
- `php artisan test` **azzera** `claudio_slimcms`: dopo ogni esecuzione lanciare `php artisan db:seed`.
- Ogni modello con dati di un cliente usa `App\Models\Concerns\BelongsToSite`; mai `forceDelete()` nella forma query.
- Importi: **interi in centesimi, IVA inclusa**. Mai float nel database.
- L'API di build espone `disponibile` (bool), **mai** il numero di scorte.
- Commenti e messaggi del pannello in italiano senza accenti (`e'`, `puo'`); testi che legge un visitatore con gli accenti veri.
- Nomi di dominio in italiano (`Prodotto`, `prodotti`, `scorte`), il resto del codice segue le convenzioni Laravel.
- Ogni commit: `git push`, poi `slimcms deploy-backend` e, se è cambiato il frontend, `slimcms deploy-frontend`. **Mai in pipe, mai da una cartella relativa.** La versione avanza da sola (commit N = 0.0.N).
- Messaggi di commit in italiano, chiusi dalle righe `Co-Authored-By` / `Claude-Session` della sessione.
- Le colonne upsell (`upsell_prodotto_id`, `upsell_titolo`, `upsell_testo`) **non** fanno parte di questo passo: arrivano col passo 2.

## Mappa dei file

| File | Responsabilità |
|---|---|
| `backend/database/migrations/2026_09_21_100000_add_shop_attivo_to_sites_table.php` | colonna `sites.shop_attivo` |
| `backend/database/migrations/2026_09_21_110000_create_prodotti_table.php` | tabella `prodotti` |
| `backend/app/Models/Site.php` | `shop_attivo` fillable/cast, `negozioAttivo()` |
| `backend/app/ControlPlane/Filament/Resources/Sites/SiteResource.php` | sezione «Moduli» con l'interruttore |
| `backend/app/Http/Resources/SiteResource.php` | `negozio.attivo` verso Astro |
| `backend/app/Models/Prodotto.php` | modello |
| `backend/app/Policies/ProdottoPolicy.php` | soglie per ruolo |
| `backend/app/Observers/ContenutoObserver.php` | percorso `/prodotti/<slug>` nella coda di build |
| `backend/app/Providers/AppServiceProvider.php` | registra l'observer |
| `backend/app/Support/Euro.php` | conversione euro ⇄ centesimi per i form |
| `backend/app/Filament/Resources/Prodotti/**` | risorsa, form, tabella, pagine |
| `backend/app/Filament/Resources/Pages/Schemas/PageForm.php` | `?Page` → `?HasMedia` nel builder condiviso |
| `backend/app/Http/Resources/Concerns/RisolveBlocchi.php` | risoluzione dei blocchi estratta da `PageResource` |
| `backend/app/Http/Resources/PageResource.php` | usa il trait |
| `backend/app/Http/Resources/ProdottoResource.php` | forma JSON del prodotto |
| `backend/app/Http/Controllers/Api/SiteProdottoController.php` | elenco e dettaglio |
| `backend/routes/api.php` | due rotte |
| `backend/app/Http/Controllers/Api/SiteSitemapController.php` | prodotti in sitemap |
| `backend/app/Http/Controllers/Api/OpenGraphController.php` | `tipo=prodotto` |
| `backend/tests/Feature/NegozioCatalogoTest.php` | test del passo |
| `backend/tests/Unit/EuroTest.php` | test della conversione |
| `backend/tests/Feature/CicloDiVitaSitoTest.php` | `shop_attivo` resta nel control plane |
| `frontend/src/lib/api.ts` | tipo `Prodotto`, `elencoProdotti()`, media dei prodotti |
| `frontend/src/lib/negozio.ts` | `euro()` |
| `frontend/src/lib/jsonld.ts` | `grafoProdottoJsonLd()`, `nodoFaq()` |
| `frontend/src/components/negozio/ContenutoProdotto.astro` | la scheda |
| `frontend/src/pages/[...percorso].astro` | tipo `prodotto` |
| `frontend/src/pages/og/prodotti/[slug].png.ts` | immagini Open Graph |
| `frontend/src/pages/ricerca-indice.json.ts`, `frontend/src/pages/cerca.astro` | prodotti nella ricerca |
| `scripts/deploy-frontend.sh` | gate: ogni scheda ha il suo `Product` |
| `CLAUDE.md` | sezione «Negozio» |

---

### Task 1: L'interruttore del negozio nel control plane

**Files:**
- Create: `backend/database/migrations/2026_09_21_100000_add_shop_attivo_to_sites_table.php`
- Modify: `backend/app/Models/Site.php` (fillable, casts, nuovo metodo)
- Modify: `backend/app/ControlPlane/Filament/Resources/Sites/SiteResource.php` (nuova sezione dopo «Stato del sito»)
- Modify: `backend/app/Http/Resources/SiteResource.php`
- Test: `backend/tests/Feature/CicloDiVitaSitoTest.php`

**Interfaces:**
- Produces: `Site::negozioAttivo(): bool`; colonna `sites.shop_attivo` (bool, default false); campo API `negozio: { attivo: bool }` in `GET /api/sites/{site}`.

- [ ] **Step 1: Test che fallisce**

In `CicloDiVitaSitoTest`, aggiungi `'shop_attivo'` all'elenco di `test_il_control_plane_tiene_cliente_dominio_e_stato`:

```php
        foreach (['tenant_id', 'domain', 'shop_attivo'] as $atteso) {
```

e aggiungi in fondo alla classe:

```php
    /**
     * Il negozio si accende da qui e non dal pannello del sito: cosa il sito
     * *puo'* fare lo decide la piattaforma, come lo usa chi lo abita.
     */
    public function test_il_negozio_si_accende_dal_control_plane(): void
    {
        $sito = Site::withoutTenancy()->create([
            'tenant_id' => $this->tenant->id, 'domain' => 'c.test', 'name' => 'C',
        ]);

        $this->assertFalse($sito->negozioAttivo(), 'Un sito nuovo nasce senza negozio.');

        Livewire::test(EditSite::class, ['record' => $sito->getRouteKey()])
            ->fillForm(['shop_attivo' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($sito->refresh()->negozioAttivo());
    }
```

Nell'elenco dei campi fuori posto di `test_la_configurazione_del_sito_non_e_piu_nel_control_plane` non cambia niente.

- [ ] **Step 2: Verifica che fallisca**

Run: `php artisan test --filter=CicloDiVitaSitoTest`
Expected: FAIL — `«shop_attivo» deve restare nel control plane.` e `Call to undefined method App\Models\Site::negozioAttivo()`.

- [ ] **Step 3: Migrazione**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Il negozio e' un modulo che la piattaforma accende sito per sito.
 *
 * Default spento: i siti che esistono non vendono niente, e accendere il
 * negozio a tutti con una migrazione farebbe comparire voci di menu che
 * nessuno ha chiesto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->boolean('shop_attivo')->default(false)->after('nota_cortesia');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('shop_attivo');
        });
    }
};
```

- [ ] **Step 4: Modello `Site`**

In `$fillable`, dopo `'nota_cortesia',` aggiungi `'shop_attivo',`. In `casts()` aggiungi `'shop_attivo' => 'boolean',`. Dopo `useAsCurrent()` aggiungi:

```php
    /**
     * Il negozio e' acceso? L'unico punto in cui lo si chiede: pannello,
     * API e sitemap passano tutti da qui.
     */
    public function negozioAttivo(): bool
    {
        return (bool) $this->shop_attivo;
    }
```

- [ ] **Step 5: Control plane**

In `ControlPlane/.../SiteResource.php` aggiungi `use Filament\Forms\Components\Toggle;` e, subito dopo la chiusura di `Section::make('Stato del sito')...->columns(1),`, inserisci:

```php
            Section::make('Moduli')
                ->description('Le funzioni in piu\' che il sito puo\' usare. Si accendono da qui, '
                    . 'si configurano dal pannello del sito.')
                ->schema([
                    Toggle::make('shop_attivo')
                        ->label('Negozio')
                        ->helperText('Prodotti, carrello e pagamenti. Spegnerlo non cancella niente: '
                            . 'prodotti e ordini restano, e il sito smette di mostrarli alla build successiva.'),
                ]),
```

`SiteObserver` accoda già una build completa su ogni colonna non operativa: `shop_attivo` non va aggiunta a `$operative`, perché accendere il negozio cambia il sito pubblicato.

- [ ] **Step 6: API verso Astro**

In `app/Http/Resources/SiteResource.php`, dopo la chiave `'captcha'`:

```php
            // Se il sito vende. La build lo usa per sapere se aspettarsi
            // prodotti; i prodotti stessi arrivano da /prodotti.
            'negozio' => [
                'attivo' => $this->negozioAttivo(),
            ],
```

- [ ] **Step 7: Migra e verifica**

Run: `php artisan migrate && php artisan test --filter=CicloDiVitaSitoTest`
Expected: PASS, 5 test.

- [ ] **Step 8: Commit e rilascio**

```bash
php artisan db:seed
git add backend/database/migrations/2026_09_21_100000_add_shop_attivo_to_sites_table.php backend/app/Models/Site.php backend/app/ControlPlane/Filament/Resources/Sites/SiteResource.php backend/app/Http/Resources/SiteResource.php backend/tests/Feature/CicloDiVitaSitoTest.php
git commit -m "Il negozio si accende dal control plane"   # + righe di attribuzione
git push
slimcms deploy-backend
```

---

### Task 2: Il modello `Prodotto`

**Files:**
- Create: `backend/database/migrations/2026_09_21_110000_create_prodotti_table.php`
- Create: `backend/app/Models/Prodotto.php`
- Create: `backend/app/Policies/ProdottoPolicy.php`
- Modify: `backend/app/Observers/ContenutoObserver.php` (`percorso()`)
- Modify: `backend/app/Providers/AppServiceProvider.php:36-38`
- Test: `backend/tests/Feature/NegozioCatalogoTest.php`

**Interfaces:**
- Consumes: `Site::negozioAttivo()` (Task 1).
- Produces: `App\Models\Prodotto` con `scopePubblicati(Builder)`, `disponibile(): bool`, collezione media `immagini`; colonne `site_id, nome, slug, descrizione, prezzo, prezzo_barrato, scorte, blocks, seo, status`; `ProdottoPolicy` (lettura viewer, scrittura author, eliminazione editor).

- [ ] **Step 1: Test che falliscono**

Crea `backend/tests/Feature/NegozioCatalogoTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\Ruolo;
use App\Models\BuildRequest;
use App\Models\Plan;
use App\Models\Prodotto;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Il catalogo del negozio: passo 1 di SlimShop.
 *
 * Un prodotto e' un contenuto come una pagina: scoped per sito, pubblicato
 * solo da un redattore, rigenera il sito quando cambia.
 */
class NegozioCatalogoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Site $sito;

    protected function setUp(): void
    {
        parent::setUp();

        $piano = Plan::create(['name' => 'T', 'price_monthly' => 0, 'max_sites' => 5, 'max_storage_gb' => 1]);
        $this->tenant = Tenant::create(['id' => 'c', 'name' => 'C', 'slug' => 'c', 'status' => 'active', 'plan_id' => $piano->id]);
        $this->sito = $this->nuovoSito('c.test');

        Filament::setCurrentPanel('admin');
        Filament::setTenant($this->sito, isQuiet: true);
        $this->sito->useAsCurrent();
    }

    private function nuovoSito(string $dominio): Site
    {
        return Site::withoutTenancy()->create([
            'tenant_id' => $this->tenant->id, 'domain' => $dominio, 'name' => $dominio, 'shop_attivo' => true,
        ]);
    }

    private function entraCome(Ruolo $ruolo): User
    {
        $utente = User::withoutSitePivotScope()->create([
            'name' => ucfirst($ruolo->value),
            'email' => $ruolo->value . '-' . uniqid() . '@c.test',
            'password' => bcrypt('x'),
        ]);
        $utente->sites()->attach($this->sito, ['role' => $ruolo->value]);
        $this->actingAs($utente);

        return $utente;
    }

    private function prodotto(array $extra = []): Prodotto
    {
        return Prodotto::create(array_merge([
            'nome' => 'Mazzo',
            'slug' => 'mazzo',
            'descrizione' => 'Un mazzo di carte.',
            'prezzo' => 3900,
            'scorte' => 10,
            'status' => 'published',
            'blocks' => [],
        ], $extra));
    }

    public function test_un_prodotto_appartiene_al_sito_corrente(): void
    {
        $p = $this->prodotto();

        $this->assertSame($this->sito->id, $p->site_id);

        $altro = $this->nuovoSito('altro.test');
        $altro->useAsCurrent();

        $this->assertNull(Prodotto::find($p->id), 'Il prodotto di un sito e\' visibile da un altro.');
    }

    public function test_disponibile_dipende_dalle_scorte(): void
    {
        $this->assertTrue($this->prodotto()->disponibile());
        $this->assertFalse($this->prodotto(['slug' => 'finito', 'scorte' => 0])->disponibile());
    }

    public function test_pubblicati_esclude_le_bozze(): void
    {
        $this->prodotto();
        $this->prodotto(['slug' => 'bozza', 'status' => 'draft']);

        $this->assertSame(['mazzo'], Prodotto::query()->pubblicati()->pluck('slug')->all());
    }

    public function test_un_autore_non_pubblica_un_prodotto(): void
    {
        $this->entraCome(Ruolo::Author);

        $this->expectException(AuthorizationException::class);

        $this->prodotto();
    }

    public function test_un_prodotto_pubblicato_accoda_una_build(): void
    {
        $this->prodotto();

        $richiesta = BuildRequest::query()->where('site_id', $this->sito->id)->latest('id')->first();

        $this->assertNotNull($richiesta, 'Pubblicare un prodotto non ha accodato nessuna build.');
        $this->assertContains('/prodotti/mazzo', $richiesta->paths ?? []);
    }

    public function test_una_bozza_non_accoda_niente(): void
    {
        // BuildRequest non e' scoped: senza il where si svuoterebbe la coda
        // di tutti i siti.
        BuildRequest::query()->where('site_id', $this->sito->id)->delete();

        $this->prodotto(['status' => 'draft']);

        $this->assertSame(0, BuildRequest::query()->where('site_id', $this->sito->id)->count());
    }
}
```

`BuildRequest` **non** usa `BelongsToSite` (le build si leggono fra siti diversi), quindi ogni query su di lei va ristretta a mano con `where('site_id', ...)`, anche nei test. La colonna `paths` ha cast `array`.

- [ ] **Step 2: Verifica che fallisca**

Run: `php artisan test --filter=NegozioCatalogoTest`
Expected: FAIL — `Class "App\Models\Prodotto" not found`.

- [ ] **Step 3: Migrazione**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Il catalogo del negozio.
 *
 * Gli importi sono interi in centesimi, IVA inclusa: con i decimali
 * 3 x 33,33 fa 99,99 e la differenza si scopre in contabilita'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prodotti', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('nome');
            $table->string('slug');
            $table->text('descrizione')->nullable();
            $table->unsignedInteger('prezzo');
            $table->unsignedInteger('prezzo_barrato')->nullable();
            // Unsigned: il database stesso rifiuta un magazzino negativo.
            $table->unsignedInteger('scorte')->default(0);
            $table->json('blocks')->nullable();
            $table->json('seo')->nullable();
            $table->string('status')->default('draft');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['site_id', 'slug']);
            $table->index(['site_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prodotti');
    }
};
```

- [ ] **Step 4: Modello**

```php
<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use App\Models\Concerns\PubblicazioneRiservata;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Un prodotto del negozio (SlimShop, specifica §2).
 *
 * Si comporta come una pagina: scoped per sito, pubblicato solo da chi ha
 * il grado di redattore, con i blocchi del page builder per il corpo della
 * scheda. In piu' ha un prezzo e un magazzino.
 */
class Prodotto extends Model implements HasMedia
{
    use BelongsToSite;
    use PubblicazioneRiservata;
    use InteractsWithMedia;
    use SoftDeletes;

    protected $table = 'prodotti';

    protected $fillable = [
        'site_id',
        'nome',
        'slug',
        'descrizione',
        'prezzo',
        'prezzo_barrato',
        'scorte',
        'blocks',
        'seo',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'prezzo' => 'integer',
            'prezzo_barrato' => 'integer',
            'scorte' => 'integer',
            'blocks' => 'array',
            'seo' => 'array',
        ];
    }

    /**
     * Le immagini del prodotto: la prima e' la copertina, i blocchi scelgono
     * fra queste. Stesso nome della collezione delle pagine, cosi' il
     * builder condiviso le trova senza sapere di che modello si tratta.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('immagini')
            ->useDisk(config('media-library.disk_name'));
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('anteprima')->fit(Fit::Contain, 320, 320)->nonQueued();
        $this->addMediaConversion('web')->fit(Fit::Max, 1600, 1600)->nonQueued();
    }

    public function scopePubblicati(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }

    public function disponibile(): bool
    {
        return $this->scorte > 0;
    }
}
```

- [ ] **Step 5: Policy**

```php
<?php

namespace App\Policies;

use App\Enums\Ruolo;

/**
 * Come pagine e articoli: l'autore scrive, il redattore pubblica ed elimina.
 * Pubblicare e' controllato a parte, sul modello (PubblicazioneRiservata).
 */
class ProdottoPolicy extends PolicyDiSito
{
    protected const LETTURA = Ruolo::Viewer;
    protected const SCRITTURA = Ruolo::Author;
    protected const ELIMINAZIONE = Ruolo::Editor;
}
```

Laravel trova la policy da solo (`App\Models\Prodotto` → `App\Policies\ProdottoPolicy`): nessuna registrazione.

- [ ] **Step 6: Observer**

In `ContenutoObserver::percorso()`, prima del `return '/';` finale:

```php
        if ($model instanceof Prodotto) {
            return '/prodotti/' . $model->slug;
        }
```

con `use App\Models\Prodotto;`. Aggiorna il docblock della classe: «Osserva Page, Post e Prodotto.»

In `AppServiceProvider`, dopo `Post::observe(...)`:

```php
        Prodotto::observe(ContenutoObserver::class);
```

con `use App\Models\Prodotto;`.

- [ ] **Step 7: Verifica**

Run: `php artisan migrate && php artisan test --filter='NegozioCatalogoTest|TenantScopeTest|PolicyRuoliTest'`
Expected: PASS. `TenantScopeTest` copre `Prodotto` da solo (ha `site_id` e il trait); `PolicyRuoliTest::test_ogni_policy_dichiara_tutte_le_abilita_che_filament_interroga` copre `ProdottoPolicy`.

- [ ] **Step 8: Commit e rilascio**

```bash
php artisan db:seed
git add backend/database/migrations/2026_09_21_110000_create_prodotti_table.php backend/app/Models/Prodotto.php backend/app/Policies/ProdottoPolicy.php backend/app/Observers/ContenutoObserver.php backend/app/Providers/AppServiceProvider.php backend/tests/Feature/NegozioCatalogoTest.php
git commit -m "Il modello dei prodotti, scoped per sito come le pagine"
git push
slimcms deploy-backend
```

---

### Task 3: «Prodotti» nel pannello del sito

**Files:**
- Create: `backend/app/Support/Euro.php`
- Create: `backend/app/Filament/Resources/Prodotti/ProdottoResource.php`
- Create: `backend/app/Filament/Resources/Prodotti/Schemas/ProdottoForm.php`
- Create: `backend/app/Filament/Resources/Prodotti/Tables/ProdottiTable.php`
- Create: `backend/app/Filament/Resources/Prodotti/Pages/{ListProdotti,CreateProdotto,EditProdotto}.php`
- Modify: `backend/app/Filament/Resources/Pages/Schemas/PageForm.php` (`blocchi()` e `immaginiDisponibili()`)
- Test: `backend/tests/Unit/EuroTest.php`, `backend/tests/Feature/NegozioCatalogoTest.php`

**Interfaces:**
- Consumes: `Prodotto`, `ProdottoPolicy` (Task 2); `Site::negozioAttivo()` (Task 1); `PageForm::blocchiPubblici()`, `PageForm::tabSeoGeoAeo()`, `PerSito::regolaUnica`, `Slug::da`, `RuoloCorrente::puoPubblicare()`.
- Produces: `Euro::inCentesimi(string|int|float|null): ?int`, `Euro::daCentesimi(?int): ?string` (formato `39,00`); `ProdottoResource::canAccess()` falso a negozio spento.

- [ ] **Step 1: Test di `Euro`**

`backend/tests/Unit/EuroTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Support\Euro;
use PHPUnit\Framework\TestCase;

class EuroTest extends TestCase
{
    public function test_legge_gli_importi_come_li_scrive_una_persona(): void
    {
        $this->assertSame(3900, Euro::inCentesimi('39'));
        $this->assertSame(3990, Euro::inCentesimi('39,90'));
        $this->assertSame(3990, Euro::inCentesimi('39.90'));
        $this->assertSame(123450, Euro::inCentesimi('1.234,50'));
        $this->assertSame(3990, Euro::inCentesimi(' € 39,90 '));
        // 0,29 * 100 in virgola mobile fa 28,999...: senza arrotondamento
        // diventerebbe 28 centesimi.
        $this->assertSame(29, Euro::inCentesimi('0,29'));
    }

    public function test_quello_che_non_e_un_importo_e_null(): void
    {
        $this->assertNull(Euro::inCentesimi(null));
        $this->assertNull(Euro::inCentesimi(''));
        $this->assertNull(Euro::inCentesimi('trenta'));
    }

    public function test_scrive_i_centesimi_all_italiana(): void
    {
        $this->assertSame('39,00', Euro::daCentesimi(3900));
        $this->assertSame('1234,50', Euro::daCentesimi(123450));
        $this->assertNull(Euro::daCentesimi(null));
    }
}
```

Run: `php artisan test --filter=EuroTest` — Expected: FAIL, classe assente.

- [ ] **Step 2: `Euro`**

```php
<?php

namespace App\Support;

/**
 * Euro nei form, centesimi nel database.
 *
 * Chi compila scrive "39,90"; il campo `numeric` di Filament lo rifiuterebbe
 * per la virgola, e un float nel database sbaglierebbe i conti. La
 * conversione sta qui, in un posto solo.
 */
final class Euro
{
    public static function inCentesimi(string|int|float|null $valore): ?int
    {
        if ($valore === null || $valore === '') {
            return null;
        }

        $testo = str_replace([' ', '€'], '', (string) $valore);

        // Con la virgola, il punto e' il separatore delle migliaia.
        if (str_contains($testo, ',')) {
            $testo = str_replace(['.', ','], ['', '.'], $testo);
        }

        if (! is_numeric($testo)) {
            return null;
        }

        return (int) round(((float) $testo) * 100);
    }

    public static function daCentesimi(?int $centesimi): ?string
    {
        return $centesimi === null ? null : number_format($centesimi / 100, 2, ',', '');
    }
}
```

Run: `php artisan test --filter=EuroTest` — Expected: PASS.

- [ ] **Step 3: Test del pannello**

Aggiungi a `NegozioCatalogoTest` (import: `App\Filament\Resources\Prodotti\Pages\CreateProdotto`, `App\Filament\Resources\Prodotti\Pages\EditProdotto`, `App\Filament\Resources\Prodotti\ProdottoResource`, `App\Filament\Resources\Posts\Pages\EditPost`, `App\Models\Post`, `Livewire\Livewire`):

```php
    public function test_un_autore_crea_un_prodotto_in_bozza(): void
    {
        $this->entraCome(Ruolo::Author);

        Livewire::test(CreateProdotto::class)
            ->fillForm([
                'nome' => 'Kit da tre',
                'slug' => 'kit-da-tre',
                'prezzo' => '99,00',
                'prezzo_barrato' => '117',
                'scorte' => 5,
                'status' => 'draft',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $p = Prodotto::query()->where('slug', 'kit-da-tre')->sole();

        $this->assertSame(9900, $p->prezzo);
        $this->assertSame(11700, $p->prezzo_barrato);
        $this->assertSame('draft', $p->status);
    }

    public function test_il_prezzo_barrato_deve_superare_il_prezzo(): void
    {
        $this->entraCome(Ruolo::Editor);

        Livewire::test(CreateProdotto::class)
            ->fillForm(['nome' => 'X', 'slug' => 'x', 'prezzo' => '39', 'prezzo_barrato' => '30', 'scorte' => 1])
            ->call('create')
            ->assertHasFormErrors(['prezzo_barrato']);
    }

    public function test_un_importo_illeggibile_e_un_errore_nel_campo(): void
    {
        $this->entraCome(Ruolo::Editor);

        Livewire::test(CreateProdotto::class)
            ->fillForm(['nome' => 'X', 'slug' => 'x', 'prezzo' => 'trenta', 'scorte' => 1])
            ->call('create')
            ->assertHasFormErrors(['prezzo']);
    }

    public function test_il_form_mostra_il_prezzo_in_euro(): void
    {
        $this->entraCome(Ruolo::Editor);
        $p = $this->prodotto();

        Livewire::test(EditProdotto::class, ['record' => $p->getRouteKey()])
            ->assertSchemaStateSet(['prezzo' => '39,00']);
    }

    public function test_a_negozio_spento_i_prodotti_non_si_aprono(): void
    {
        $this->entraCome(Ruolo::Admin);
        $this->sito->forceFill(['shop_attivo' => false])->save();

        $this->assertFalse(ProdottoResource::canAccess());
        $this->get(ProdottoResource::getUrl('index', tenant: $this->sito))->assertForbidden();
    }

    /**
     * Il builder e' condiviso da pagine, articoli e prodotti. I suoi blocchi
     * con immagini dichiaravano `?Page $record`: su un altro modello Filament
     * passa comunque il record per nome, e PHP alza un TypeError.
     */
    public function test_una_galleria_si_apre_anche_fuori_dalle_pagine(): void
    {
        $this->entraCome(Ruolo::Editor);
        $galleria = [['type' => 'galleria', 'data' => ['titolo' => 'Foto', 'media' => []]]];

        $p = $this->prodotto(['blocks' => $galleria]);
        Livewire::test(EditProdotto::class, ['record' => $p->getRouteKey()])->assertOk();

        $post = Post::create(['title' => 'A', 'slug' => 'a', 'status' => 'draft', 'blocks' => $galleria]);
        Livewire::test(EditPost::class, ['record' => $post->getRouteKey()])->assertOk();
    }
```

Run: `php artisan test --filter=NegozioCatalogoTest` — Expected: FAIL, classi Filament assenti.

- [ ] **Step 4: Il builder condiviso accetta ogni modello con media**

In `PageForm.php` aggiungi `use Spatie\MediaLibrary\HasMedia;`. Dentro `blocchi()` sostituisci **ogni** `?Page $record` con `?HasMedia $record` (sono le closure `options` e `helperText` dei blocchi `galleria` e degli altri due blocchi con immagini). Cambia la firma:

```php
    private static function immaginiDisponibili(?HasMedia $record): array
```

Il resto del metodo non cambia: `getMedia('immagini')` su un modello senza quella collezione (un articolo) restituisce una collezione vuota. Lascia `?Page` nel metodo `configure()`: lì il record è sempre una pagina.

- [ ] **Step 5: Risorsa**

`backend/app/Filament/Resources/Prodotti/ProdottoResource.php`:

```php
<?php

namespace App\Filament\Resources\Prodotti;

use App\Filament\Resources\Prodotti\Pages\CreateProdotto;
use App\Filament\Resources\Prodotti\Pages\EditProdotto;
use App\Filament\Resources\Prodotti\Pages\ListProdotti;
use App\Filament\Resources\Prodotti\Schemas\ProdottoForm;
use App\Filament\Resources\Prodotti\Tables\ProdottiTable;
use App\Models\Prodotto;
use App\Models\Site;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ProdottoResource extends Resource
{
    protected static ?string $model = Prodotto::class;

    protected static ?string $modelLabel = 'prodotto';

    protected static ?string $pluralModelLabel = 'prodotti';

    protected static ?string $navigationLabel = 'Prodotti';

    protected static ?string $recordTitleAttribute = 'nome';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    /**
     * Il negozio lo accende il control plane. Spento, la voce sparisce e la
     * URL risponde 403: nascondere la voce da sola non sarebbe un controllo.
     */
    public static function canAccess(): bool
    {
        $sito = Filament::getTenant();

        return $sito instanceof Site && $sito->negozioAttivo() && parent::canAccess();
    }

    public static function form(Schema $schema): Schema
    {
        return ProdottoForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProdottiTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProdotti::route('/'),
            'create' => CreateProdotto::route('/create'),
            'edit' => EditProdotto::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
```

- [ ] **Step 6: Form**

`backend/app/Filament/Resources/Prodotti/Schemas/ProdottoForm.php`:

```php
<?php

namespace App\Filament\Resources\Prodotti\Schemas;

use App\Filament\Resources\Pages\Schemas\PageForm;
use App\Support\Euro;
use App\Support\PerSito;
use App\Support\RuoloCorrente;
use App\Support\Slug;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

/**
 * Form di un prodotto.
 *
 * I prezzi si scrivono in euro ("39,90") e si salvano in centesimi: la
 * conversione sta in `Euro`, non sparsa nei campi. Blocchi e tab SEO sono
 * quelli di PageForm, per la stessa ragione per cui li riusa PostForm.
 */
class ProdottoForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make()->columnSpanFull()->tabs([

                Tabs\Tab::make('Prodotto')->schema([
                    TextInput::make('nome')
                        ->label('Nome')
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (?string $state, callable $set) => $set('slug', Slug::da($state))),

                    TextInput::make('slug')
                        ->label('Slug')
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true, modifyRuleUsing: PerSito::regolaUnica(...))
                        ->helperText('L\'indirizzo della scheda: /prodotti/<slug>/'),

                    Textarea::make('descrizione')
                        ->label('Descrizione breve')
                        ->rows(3)
                        ->maxLength(400)
                        ->columnSpanFull()
                        ->helperText('Sotto il nome nella scheda, e nelle anteprime quando il link viene condiviso.'),

                    self::importo('prezzo', 'Prezzo')
                        ->required()
                        ->helperText('IVA inclusa, come lo paga chi compra.'),

                    self::importo('prezzo_barrato', 'Prezzo barrato')
                        ->rule(fn (Get $get): Closure => function (string $attributo, mixed $valore, Closure $fail) use ($get): void {
                            $barrato = Euro::inCentesimi($valore);

                            if ($barrato !== null && $barrato <= (int) Euro::inCentesimi($get('prezzo'))) {
                                $fail('Il prezzo barrato e\' quello «di prima»: deve essere piu\' alto del prezzo.');
                            }
                        })
                        ->helperText('Facoltativo. Compare barrato sopra il prezzo.'),

                    TextInput::make('scorte')
                        ->label('Pezzi in magazzino')
                        ->integer()
                        ->minValue(0)
                        ->default(0)
                        ->required()
                        ->helperText('A zero la scheda mostra «esaurito».'),

                    // Fuori dal builder per la stessa ragione della pagina:
                    // dentro un blocco l'upload cancella la propria chiave e il
                    // blocco non sa piu' quali immagini sono sue.
                    SpatieMediaLibraryFileUpload::make('libreria')
                        ->label('Immagini del prodotto')
                        ->collection('immagini')
                        ->multiple()
                        ->reorderable()
                        ->appendFiles()
                        ->image()
                        ->maxSize(8192)
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                        ->columnSpanFull()
                        ->helperText('La prima e\' la foto principale della scheda. Il testo alternativo si imposta dalla libreria media.'),
                ])->columns(2),

                Tabs\Tab::make('Pagina')->schema([
                    PageForm::blocchiPubblici(),
                ])->columns(1),

                Tabs\Tab::make('Pubblicazione')->schema([
                    Select::make('status')
                        ->label('Stato')
                        ->options([
                            'draft' => 'Bozza',
                            'published' => 'Pubblicato',
                        ])
                        ->default('draft')
                        ->required()
                        // Come in PageForm: chi non puo' pubblicare puo' solo
                        // lasciare lo stato com'e'. Il modello lo rifiuta di
                        // nuovo (PubblicazioneRiservata).
                        ->disableOptionWhen(fn (string $value, ?Model $record): bool => ! RuoloCorrente::puoPubblicare()
                            && $value !== ($record?->status ?? 'draft'))
                        ->helperText(fn (): ?string => RuoloCorrente::puoPubblicare()
                            ? null
                            : 'Il tuo ruolo su questo sito non consente di pubblicare: salva come bozza, un redattore lo mettera\' online.'),
                ]),

                ...PageForm::tabSeoGeoAeo(),
            ]),
        ]);
    }

    /** Un importo in euro nel form, in centesimi nel database. */
    private static function importo(string $nome, string $etichetta): TextInput
    {
        return TextInput::make($nome)
            ->label($etichetta)
            ->prefix('€')
            ->inputMode('decimal')
            ->placeholder('39,90')
            ->formatStateUsing(fn (mixed $state): ?string => is_int($state) ? Euro::daCentesimi($state) : $state)
            ->dehydrateStateUsing(fn (mixed $state): ?int => Euro::inCentesimi($state))
            ->rule(fn (): Closure => function (string $attributo, mixed $valore, Closure $fail): void {
                if ($valore !== null && $valore !== '' && Euro::inCentesimi($valore) === null) {
                    $fail('Scrivi un importo, per esempio 39,90.');
                }
            });
    }
}
```

- [ ] **Step 7: Tabella**

`backend/app/Filament/Resources/Prodotti/Tables/ProdottiTable.php`:

```php
<?php

namespace App\Filament\Resources\Prodotti\Tables;

use App\Support\Euro;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class ProdottiTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nome')->label('Nome')->searchable()->sortable()->weight('medium'),

                TextColumn::make('prezzo')
                    ->label('Prezzo')
                    ->formatStateUsing(fn (int $state): string => '€ ' . Euro::daCentesimi($state))
                    ->sortable(),

                TextColumn::make('scorte')
                    ->label('Magazzino')
                    ->sortable()
                    ->badge()
                    ->color(fn (int $state): string => $state === 0 ? 'danger' : ($state < 5 ? 'warning' : 'gray'))
                    ->formatStateUsing(fn (int $state): string => $state === 0 ? 'esaurito' : (string) $state),

                TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'published' ? 'Pubblicato' : 'Bozza')
                    ->color(fn (string $state): string => $state === 'published' ? 'success' : 'gray'),

                TextColumn::make('updated_at')->label('Modificato')->since()->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('nome')
            ->filters([
                SelectFilter::make('status')->label('Stato')->options([
                    'draft' => 'Bozza',
                    'published' => 'Pubblicato',
                ]),
                TrashedFilter::make()->label('Cestino'),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                // Niente ForceDeleteBulkAction: vedi CLAUDE.md, regola 2-bis.
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
```

- [ ] **Step 8: Pagine**

`Pages/ListProdotti.php`:

```php
<?php

namespace App\Filament\Resources\Prodotti\Pages;

use App\Filament\Resources\Prodotti\ProdottoResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProdotti extends ListRecords
{
    protected static string $resource = ProdottoResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
```

`Pages/CreateProdotto.php`:

```php
<?php

namespace App\Filament\Resources\Prodotti\Pages;

use App\Filament\Resources\Prodotti\ProdottoResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProdotto extends CreateRecord
{
    protected static string $resource = ProdottoResource::class;
}
```

`Pages/EditProdotto.php`:

```php
<?php

namespace App\Filament\Resources\Prodotti\Pages;

use App\Filament\Resources\Prodotti\ProdottoResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditProdotto extends EditRecord
{
    protected static string $resource = ProdottoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
```

- [ ] **Step 9: Verifica**

Run: `php artisan test --filter='NegozioCatalogoTest|EuroTest|PolicyRuoliTest'`
Expected: PASS. `PolicyRuoliTest::test_ogni_risorsa_del_pannello_ha_una_policy` trova `ProdottoResource` → `ProdottoPolicy`.

Se `test_il_form_mostra_il_prezzo_in_euro` fallisce perché lo stato vale `3900`, significa che `formatStateUsing` riceve una stringa: allarga la condizione a `is_numeric($state) && ! str_contains((string) $state, ',')` e rilancia.

- [ ] **Step 10: Suite completa, commit e rilascio**

```bash
php artisan test
php artisan db:seed
git add backend/app/Support/Euro.php backend/app/Filament/Resources/Prodotti backend/app/Filament/Resources/Pages/Schemas/PageForm.php backend/tests/Unit/EuroTest.php backend/tests/Feature/NegozioCatalogoTest.php
git commit -m "Prodotti nel pannello del sito, visibili solo a negozio acceso"
git push
slimcms deploy-backend
```

Expected: suite intera verde. Poi prova a mano: accendi il negozio su un sito demo da `/manage/sites`, apri `/admin/<sito>/prodotti` e crea un prodotto con una foto.

---

### Task 4: API del catalogo per la build

**Files:**
- Create: `backend/app/Http/Resources/Concerns/RisolveBlocchi.php`
- Modify: `backend/app/Http/Resources/PageResource.php` (sposta `blocchiRisolti()` e `moduloRisolto()` nel trait)
- Create: `backend/app/Http/Resources/ProdottoResource.php`
- Create: `backend/app/Http/Controllers/Api/SiteProdottoController.php`
- Modify: `backend/routes/api.php`
- Modify: `backend/app/Http/Controllers/Api/SiteSitemapController.php`
- Modify: `backend/app/Http/Controllers/Api/OpenGraphController.php`
- Test: `backend/tests/Feature/NegozioCatalogoTest.php`

**Interfaces:**
- Consumes: `Prodotto` (Task 2), `Site::negozioAttivo()` (Task 1), `ConMedia::mediaPubblico()`.
- Produces: `GET /api/sites/{site}/prodotti` → `{ data: ProdottoJson[] }`, `GET /api/sites/{site}/prodotti/{slug}` → `{ data: ProdottoJson }`, dove `ProdottoJson` = `{ id, nome, slug, descrizione, prezzo, prezzo_barrato, valuta, disponibile, immagini: Media[], blocks, updated_at, seo{meta_title, meta_description, canonical_url, noindex, og_title, og_description, og_image}, geo{structured_summary, key_facts}, aeo{direct_answer, faq, schema_type} }`; `GET .../og/{slug}.png?tipo=prodotto`; URL `https://<dominio>/prodotti/<slug>/` in sitemap.

- [ ] **Step 1: Test che falliscono**

Aggiungi a `NegozioCatalogoTest` (import `Laravel\Sanctum\Sanctum`):

```php
    private function api(string $percorso): \Illuminate\Testing\TestResponse
    {
        $utente = $this->entraCome(Ruolo::Editor);
        Sanctum::actingAs($utente, ["site:{$this->sito->id}"]);

        return $this->getJson("/api/sites/{$this->sito->domain}{$percorso}");
    }

    public function test_l_api_consegna_i_prodotti_pubblicati(): void
    {
        $this->prodotto(['prezzo_barrato' => 4500]);
        $this->prodotto(['slug' => 'bozza', 'status' => 'draft']);

        $dati = $this->api('/prodotti')->assertOk()->json('data');

        $this->assertCount(1, $dati);
        $this->assertSame('mazzo', $dati[0]['slug']);
        $this->assertSame(3900, $dati[0]['prezzo']);
        $this->assertSame(4500, $dati[0]['prezzo_barrato']);
        $this->assertSame('EUR', $dati[0]['valuta']);
        $this->assertTrue($dati[0]['disponibile']);
        $this->assertSame('Product', $dati[0]['aeo']['schema_type']);
    }

    /** Il magazzino di un cliente non e' un dato pubblico. */
    public function test_l_api_non_rivela_quanti_pezzi_ci_sono(): void
    {
        $this->prodotto(['scorte' => 7]);

        $dati = $this->api('/prodotti/mazzo')->assertOk()->json('data');

        $this->assertArrayNotHasKey('scorte', $dati);
        $this->assertStringNotContainsString('"scorte"', json_encode($dati));
    }

    public function test_un_prezzo_barrato_non_piu_alto_non_esce(): void
    {
        // Il form lo impedisce; se un dato storto arriva lo stesso nel
        // database, il sito non deve mostrare "prima 30 €, ora 39 €".
        $this->prodotto(['prezzo_barrato' => 3000]);

        $this->assertNull($this->api('/prodotti/mazzo')->json('data.prezzo_barrato'));
    }

    public function test_a_negozio_spento_l_elenco_e_vuoto_e_il_dettaglio_404(): void
    {
        $this->prodotto();
        $this->sito->forceFill(['shop_attivo' => false])->save();

        $this->assertSame([], $this->api('/prodotti')->assertOk()->json('data'));
        $this->api('/prodotti/mazzo')->assertNotFound();
    }

    public function test_i_prodotti_entrano_nella_sitemap_solo_a_negozio_acceso(): void
    {
        $this->prodotto();
        $url = 'https://c.test/prodotti/mazzo/';

        $this->assertContains($url, array_column($this->api('/sitemap')->json('urls'), 'loc'));

        $this->sito->forceFill(['shop_attivo' => false])->save();

        $this->assertNotContains($url, array_column($this->api('/sitemap')->json('urls'), 'loc'));
    }

    public function test_un_prodotto_ha_la_sua_immagine_open_graph(): void
    {
        $this->prodotto();

        $this->api('/og/mazzo.png?tipo=prodotto')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }
```

Run: `php artisan test --filter=NegozioCatalogoTest` — Expected: FAIL, 404 su `/prodotti`.

- [ ] **Step 2: Estrai la risoluzione dei blocchi**

Crea `backend/app/Http/Resources/Concerns/RisolveBlocchi.php` con namespace `App\Http\Resources\Concerns`, `trait RisolveBlocchi`, e **sposta** dentro, identici e con i loro commenti, i metodi `blocchiRisolti()` e `moduloRisolto()` di `PageResource`. Docblock del trait:

```php
/**
 * Blocchi del page builder pronti per Astro: immagini e moduli risolti.
 *
 * Condiviso da pagine e prodotti, che usano lo stesso builder e la stessa
 * collezione `immagini`. Due copie divergerebbero alla prima modifica, e il
 * sintomo sarebbe una galleria vuota su un tipo di contenuto solo.
 * Richiede ConMedia.
 */
```

In `PageResource` rimuovi i due metodi e aggiungi `use RisolveBlocchi;` accanto a `use ConMedia;` (con l'import `App\Http\Resources\Concerns\RisolveBlocchi`).

Run: `php artisan test --filter='ContrattoBlocchiTest|MediaNeiBlocchiTest'` — Expected: PASS, invariati.

- [ ] **Step 3: Resource**

`backend/app/Http/Resources/ProdottoResource.php`:

```php
<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\ConMedia;
use App\Http\Resources\Concerns\RisolveBlocchi;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un prodotto come lo riceve la build di Astro.
 *
 * `disponibile` e non `scorte`: quanti pezzi ha in magazzino un cliente non
 * e' un dato da pubblicare nell'HTML di un sito.
 */
class ProdottoResource extends JsonResource
{
    use ConMedia;
    use RisolveBlocchi;

    public function toArray(Request $request): array
    {
        $seo = $this->seo ?? [];

        return [
            'id' => $this->id,
            'nome' => $this->nome,
            'slug' => $this->slug,
            'descrizione' => $this->descrizione,
            'prezzo' => (int) $this->prezzo,
            // Il form lo vuole piu' alto del prezzo; se un dato storto arriva
            // lo stesso, meglio nessun barrato che "prima 30, ora 39".
            'prezzo_barrato' => $this->prezzo_barrato !== null && $this->prezzo_barrato > $this->prezzo
                ? (int) $this->prezzo_barrato
                : null,
            'valuta' => 'EUR',
            'disponibile' => $this->disponibile(),
            'immagini' => $this->getMedia('immagini')
                ->map(fn ($m) => $this->mediaPubblico($m))
                ->values(),
            'blocks' => $this->blocchiRisolti(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            'seo' => [
                'meta_title' => $seo['meta_title'] ?? $this->nome,
                'meta_description' => $seo['meta_description'] ?? $this->descrizione,
                'canonical_url' => $seo['canonical_url'] ?? null,
                'noindex' => (bool) ($seo['noindex'] ?? false),
                'og_title' => $seo['og_title'] ?? $seo['meta_title'] ?? $this->nome,
                'og_description' => $seo['og_description'] ?? $seo['meta_description'] ?? $this->descrizione,
                'og_image' => $seo['og_image'] ?? null,
            ],

            'geo' => [
                'structured_summary' => $seo['structured_summary'] ?? null,
                'key_facts' => array_values($seo['key_facts'] ?? []),
            ],

            'aeo' => [
                'direct_answer' => $seo['direct_answer'] ?? null,
                'faq' => array_values($seo['faq_block'] ?? []),
                'schema_type' => 'Product',
            ],
        ];
    }
}
```

- [ ] **Step 4: Controller e rotte**

`backend/app/Http/Controllers/Api/SiteProdottoController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProdottoResource;
use App\Models\Prodotto;
use App\Models\Site;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Il catalogo, letto dal worker di build.
 *
 * Il filtro per sito lo applica il global scope, come per pagine e articoli.
 */
class SiteProdottoController extends Controller
{
    /**
     * A negozio spento un elenco vuoto, non un 404: la build di ogni sito
     * chiama questo endpoint, e un sito senza negozio deve semplicemente non
     * avere schede prodotto — non fallire.
     */
    public function index(Site $site): AnonymousResourceCollection
    {
        $prodotti = $site->negozioAttivo()
            ? Prodotto::query()->pubblicati()->with('media')->orderBy('nome')->get()
            : collect();

        return ProdottoResource::collection($prodotti);
    }

    public function show(Site $site, string $slug): ProdottoResource
    {
        abort_unless($site->negozioAttivo(), 404);

        return new ProdottoResource(
            Prodotto::query()->pubblicati()->with('media')->where('slug', $slug)->firstOrFail()
        );
    }
}
```

In `routes/api.php`, import `App\Http\Controllers\Api\SiteProdottoController;` e dentro il gruppo `sites/{site}`, dopo le rotte `posts`:

```php
        Route::get('prodotti', [SiteProdottoController::class, 'index']);
        Route::get('prodotti/{slug}', [SiteProdottoController::class, 'show']);
```

- [ ] **Step 5: Sitemap**

In `SiteSitemapController::__invoke`, aggiungi `->concat($this->prodotti($base, $site))` dopo `->concat($this->archivi($base, $blog))`, l'import `App\Models\Prodotto`, e il metodo:

```php
    /** Le schede prodotto, solo a negozio acceso. */
    private function prodotti(string $base, Site $site)
    {
        if (! $site->negozioAttivo()) {
            return collect();
        }

        return Prodotto::query()->pubblicati()->get()
            ->reject(fn (Prodotto $p) => (bool) ($p->seo['noindex'] ?? false))
            ->map(fn (Prodotto $p) => [
                'loc' => "{$base}/prodotti/{$p->slug}/",
                'lastmod' => $p->updated_at?->toIso8601String(),
                'changefreq' => $p->seo['sitemap_change_freq'] ?? 'weekly',
                'priority' => $p->seo['sitemap_priority'] ?? '0.8',
            ]);
    }
```

- [ ] **Step 6: Open Graph**

In `OpenGraphController::contenuto`, import `App\Models\Prodotto`, e:

```php
        $contenuto = match ($request->string('tipo')->toString()) {
            'articolo' => Post::where('slug', $slug)->first(),
            'prodotto' => Prodotto::where('slug', $slug)->first(),
            'pagina' => Page::where('slug', $slug)->first(),
            default => Page::where('slug', $slug)->first() ?? Post::where('slug', $slug)->first(),
        };

        // Un prodotto ha un nome, non un titolo.
        $titolo = $contenuto?->title ?? $contenuto?->nome ?? $site->name ?? $site->domain;
```

e la chiave:

```php
        $chiave = match (true) {
            $contenuto instanceof Post => 'articolo',
            $contenuto instanceof Prodotto => 'prodotto',
            default => 'pagina',
        } . ':' . $slug;
```

Aggiorna il docblock di `contenuto()`: «Immagine di una pagina, di un articolo o di un prodotto.»

- [ ] **Step 7: Verifica**

Run: `php artisan test --filter='NegozioCatalogoTest|ContrattoBlocchiTest|BlogTest'`
Expected: PASS.

- [ ] **Step 8: Suite completa, commit e rilascio**

```bash
php artisan test
php artisan db:seed
git add backend/app/Http backend/routes/api.php backend/tests/Feature/NegozioCatalogoTest.php
git commit -m "API del catalogo per la build, prodotti in sitemap e Open Graph"
git push
slimcms deploy-backend
```

---

### Task 5: Le schede prodotto sul sito statico

**Files:**
- Modify: `frontend/src/lib/api.ts`
- Create: `frontend/src/lib/negozio.ts`
- Modify: `frontend/src/lib/jsonld.ts`
- Create: `frontend/src/components/negozio/ContenutoProdotto.astro`
- Modify: `frontend/src/pages/[...percorso].astro`
- Create: `frontend/src/pages/og/prodotti/[slug].png.ts`
- Modify: `frontend/src/pages/ricerca-indice.json.ts`, `frontend/src/pages/cerca.astro:154-158`

**Interfaces:**
- Consumes: le API del Task 4, `Sito.negozio` (Task 1).
- Produces: `interface Prodotto`, `elencoProdotti(): Promise<Prodotto[]>`, `euro(centesimi: number): string`, `grafoProdottoJsonLd(p: Prodotto, dominio: string, venditore: string)`, pagine `/prodotti/<slug>/`, file `/og/prodotti/<slug>.png`. Il passo 2 aggiungerà il carrello in `negozio.ts`.

Il frontend non ha test automatici: la verifica è la build (`npm run build`) più il gate di `scripts/deploy-frontend.sh --dry-run`, che controlla CSS, immagini, Open Graph e sitemap.

- [ ] **Step 1: Tipi e chiamate in `api.ts`**

Nell'interfaccia `Sito`, dopo `cortesia?`:

```ts
  /** Il negozio lo accende il control plane. Assente su un backend vecchio. */
  negozio?: { attivo: boolean };
```

Cambia la firma di `immagineOpenGraph`:

```ts
  tipo: 'pagina' | 'articolo' | 'prodotto' = 'pagina'
```

Dopo `elencoArticoli()` aggiungi:

```ts
export interface Prodotto {
  id: number;
  nome: string;
  slug: string;
  descrizione: string | null;
  /** In centesimi, IVA inclusa. */
  prezzo: number;
  prezzo_barrato: number | null;
  valuta: 'EUR';
  /** Si o no, mai il numero di pezzi: non e' un dato pubblico. */
  disponibile: boolean;
  immagini: Media[];
  blocks: Blocco[];
  updated_at: string | null;
  seo: Pagina['seo'];
  geo: { structured_summary: string | null; key_facts: string[] };
  aeo: Pagina['aeo'];
}

/**
 * I prodotti pubblicati. Vuoto a negozio spento: il backend risponde con un
 * elenco vuoto e la build semplicemente non genera schede.
 */
export async function elencoProdotti(): Promise<Prodotto[]> {
  const { data } = await chiama<{ data: Prodotto[] }>('/prodotti');
  return data;
}
```

In `elencoMedia()` sostituisci la riga del `cammina`:

```ts
  // Pagine, articoli E prodotti: ognuno porta le sue immagini, e camminarne
  // solo una parte lascerebbe le altre a puntare a file mai scaricati.
  cammina(await Promise.all([elencoPagine(), elencoArticoli(), elencoProdotti()]));
```

- [ ] **Step 2: `negozio.ts`**

```ts
/**
 * Il negozio lato sito. Per ora solo la formattazione dei prezzi; il passo 2
 * ci aggiunge il carrello.
 */

const formato = new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' });

/** 3900 -> "39,00 €". I prezzi viaggiano in centesimi, mai in float. */
export const euro = (centesimi: number): string => formato.format(centesimi / 100);
```

- [ ] **Step 3: JSON-LD**

In `jsonld.ts` aggiorna l'import in `import { percorsoMedia, type Articolo, type Pagina, type Prodotto, type Termine } from './api';`. Estrai il nodo FAQ da `grafoDaCampi` in una funzione e usala lì:

```ts
/** Le domande frequenti come FAQPage, o niente se non ce ne sono. */
function nodoFaq(faq: { domanda: string; risposta: string }[]): Record<string, unknown>[] {
  if (faq.length === 0) return [];

  return [
    {
      '@context': 'https://schema.org',
      '@type': 'FAQPage',
      mainEntity: faq.map((v) => ({
        '@type': 'Question',
        name: v.domanda,
        acceptedAnswer: { '@type': 'Answer', text: v.risposta },
      })),
    },
  ];
}
```

In `grafoDaCampi` sostituisci il blocco `if (c.faq.length > 0) { nodi.push({...}) }` con `nodi.push(...nodoFaq(c.faq));`. Poi aggiungi:

```ts
/**
 * Il grafo di un PRODOTTO: Product con la sua Offer.
 *
 * Il prezzo va in decimale ("39.00"), non in centesimi: e' il formato che
 * Schema.org e Google si aspettano. Il venditore e' il sito, non l'editore
 * della piattaforma: chi vende e' il cliente.
 */
export function grafoProdottoJsonLd(p: Prodotto, dominio: string, venditore: string) {
  const url = `https://${dominio}/prodotti/${p.slug}/`;

  const prodotto = pulisci({
    '@context': 'https://schema.org',
    '@type': 'Product',
    name: p.nome,
    description: p.geo.structured_summary ?? p.descrizione ?? p.seo.meta_description ?? undefined,
    url,
    image: p.immagini.length > 0
      ? p.immagini.map((m) => `https://${dominio}${percorsoMedia(m)}`)
      : undefined,
    offers: {
      '@type': 'Offer',
      url,
      priceCurrency: p.valuta,
      price: (p.prezzo / 100).toFixed(2),
      availability: p.disponibile ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
      seller: { '@type': 'Organization', name: venditore },
    },
  });

  return [prodotto, ...nodoFaq(p.aeo.faq)];
}
```

- [ ] **Step 4: La scheda**

`frontend/src/components/negozio/ContenutoProdotto.astro`:

```astro
---
import Blocchi from '../blocchi/Blocchi.astro';
import { percorsoMedia, type Prodotto } from '../../lib/api';
import { euro } from '../../lib/negozio';

interface Props {
  prodotto: Prodotto;
}

const { prodotto } = Astro.props;
const [copertina, ...altre] = prodotto.immagini;
---

<article>
  <header class="apertura scheda-prodotto">
    {copertina && (
      <figure class="prodotto-foto">
        <img
          src={percorsoMedia(copertina)}
          alt={copertina.alt ?? prodotto.nome}
          width="800"
          height="800"
        />
      </figure>
    )}

    <div class="prodotto-testo">
      <h1>{prodotto.nome}</h1>

      {prodotto.descrizione && <p class="sommario">{prodotto.descrizione}</p>}

      <p class="prezzo">
        {prodotto.prezzo_barrato && <del class="prezzo-prima">{euro(prodotto.prezzo_barrato)}</del>}
        <strong class="prezzo-ora">{euro(prodotto.prezzo)}</strong>
        <span class="prezzo-nota">IVA inclusa</span>
      </p>

      {/* Il pulsante arriva col passo 2, insieme al carrello. */}
      <p class={prodotto.disponibile ? 'disponibilita' : 'disponibilita disponibilita-no'}>
        {prodotto.disponibile ? 'Disponibile' : 'Esaurito'}
      </p>
    </div>
  </header>

  {altre.length > 0 && (
    <div class="prodotto-galleria">
      {altre.map((m) => (
        <img src={percorsoMedia(m)} alt={m.alt ?? ''} width="400" height="400" loading="lazy" />
      ))}
    </div>
  )}

  <Blocchi blocchi={prodotto.blocks} colonne={1} />
</article>

<style>
  .scheda-prodotto {
    display: grid;
    gap: 2.5rem;
    align-items: center;
  }
  @media (min-width: 760px) {
    .scheda-prodotto { grid-template-columns: 1fr 1fr; }
  }

  .prodotto-foto { margin: 0; }
  .prodotto-foto img,
  .prodotto-galleria img {
    width: 100%;
    height: auto;
    aspect-ratio: 1 / 1;
    object-fit: cover;
    border-radius: 3px;
  }

  .prodotto-testo h1 { margin-top: 0; }

  .prezzo {
    display: flex;
    align-items: baseline;
    flex-wrap: wrap;
    gap: 0.4rem 0.8rem;
    margin: 1.8rem 0 0;
    font-family: var(--display);
  }
  .prezzo-ora { font-size: 2rem; }
  .prezzo-prima { color: var(--inchiostro-tenue); }
  .prezzo-nota { font-size: 0.85rem; color: var(--inchiostro-tenue); }

  .disponibilita {
    margin: 0.8rem 0 0;
    font-family: var(--display);
    font-size: 0.9rem;
  }
  .disponibilita-no { color: var(--inchiostro-tenue); }

  .prodotto-galleria {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: 1rem;
    max-width: var(--misura);
    margin: 2.5rem auto 0;
    padding: 0 1.5rem;
  }
</style>
```

`apertura` e `sommario`, e le variabili `--inchiostro-tenue`, `--display`, `--misura`, sono globali in `Base.astro`. Se il gate CSS dello Step 7 dice che una classe non ha regole, la correzione è aggiungere la regola in questo `<style>`, non togliere la classe.

- [ ] **Step 5: La rotta**

In `[...percorso].astro`:

1. Import: aggiungi `ContenutoProdotto` da `'../components/negozio/ContenutoProdotto.astro'`, `elencoProdotti` e `type Prodotto` da `'../lib/api'`, `grafoProdottoJsonLd` da `'../lib/jsonld'`.
2. In `getStaticPaths`:

```ts
  const [pagine, articoli, prodotti, s] = await Promise.all([
    elencoPagine(),
    elencoArticoli(),
    elencoProdotti(),
    sito(),
  ]);
```

e prima di `return voci;`:

```ts
  // Le schede prodotto. Il segmento e' fisso: a differenza del blog, nessun
  // cliente ha ancora chiesto di cambiarlo, e farlo dopo e' una modifica
  // locale. A negozio spento l'elenco arriva vuoto.
  for (const prodotto of prodotti) {
    voci.push({
      params: { percorso: `prodotti/${prodotto.slug}` },
      props: { tipo: 'prodotto', prodotto, sito: s },
    });
  }
```

3. Nel tipo delle props: `tipo: 'pagina' | 'indice' | 'articolo' | 'archivio' | 'prodotto';` e `prodotto?: Prodotto;`.
4. Nella catena dei documenti, prima del ramo `else if (props.tipo === 'indice')`:

```ts
} else if (props.tipo === 'prodotto') {
  const p = props.prodotto!;
  documento = {
    percorso: `/prodotti/${p.slug}/`,
    titolo: p.seo.meta_title,
    descrizione: p.seo.meta_description,
    ogTitolo: p.seo.og_title,
    ogDescrizione: p.seo.og_description,
    noindex: p.seo.noindex,
    canonico: p.seo.canonical_url,
    // Cartella propria, come gli articoli: lo stesso slug puo' esistere
    // anche come pagina.
    og: `/og/prodotti/${p.slug}.png`,
    jsonld: grafoProdottoJsonLd(p, dominio, infoSito.name),
  };
```

5. Nel markup, dopo la riga di `ContenutoArticolo`:

```astro
  {props.tipo === 'prodotto' && <ContenutoProdotto prodotto={props.prodotto!} />}
```

- [ ] **Step 6: Open Graph e ricerca**

`frontend/src/pages/og/prodotti/[slug].png.ts`:

```ts
import type { APIRoute } from 'astro';
import { elencoProdotti, immagineOpenGraph } from '../../../lib/api';

/**
 * Immagini Open Graph dei PRODOTTI, in una cartella propria come quelle
 * degli articoli: gli slug vivono in tabelle diverse e possono coincidere.
 */
export async function getStaticPaths() {
  const prodotti = await elencoProdotti();

  return prodotti.map((p) => ({ params: { slug: p.slug } }));
}

export const GET: APIRoute = async ({ params }) => {
  const byte = await immagineOpenGraph(String(params.slug), 'prodotto');

  return new Response(byte, {
    headers: {
      'Content-Type': 'image/png',
      'Cache-Control': 'public, max-age=3600',
    },
  });
};
```

In `ricerca-indice.json.ts`: importa `elencoProdotti` e `type Prodotto`, leggi `const [pagine, articoli, prodotti, s] = await Promise.all([elencoPagine(), elencoArticoli(), elencoProdotti(), sito()]);` e aggiungi a `voci`:

```ts
    ...prodotti.map((p: Prodotto) => ({
      titolo: p.nome,
      percorso: `/prodotti/${p.slug}/`,
      tipo: 'prodotto' as const,
      sommario: p.descrizione ?? p.geo?.structured_summary ?? p.seo?.meta_description ?? null,
      testo: testoDeiBlocchi(p.blocks).slice(0, TETTO_TESTO),
    })),
```

In `cerca.astro`, sostituisci il blocco `if (voce.tipo === 'articolo') { ... }` con:

```js
            // Pagine senza etichetta: sono il caso normale. Articoli e
            // prodotti la portano, perche' chi cerca vuole sapere cosa apre.
            if (voce.tipo === 'articolo' || voce.tipo === 'prodotto') {
              var tag = document.createElement('span');
              tag.className = 'tipo';
              tag.textContent = voce.tipo;
              li.appendChild(tag);
            }
```

- [ ] **Step 7: Build e gate in prova**

Serve un sito di sviluppo con il negozio acceso e un prodotto pubblicato con una foto. Dal backend, con il server di sviluppo avviato (`php -S 127.0.0.1:8000 -t public public/index.php`), accendi il negozio su `slimcms.it` **solo nel database di sviluppo** e crea un prodotto dal pannello, oppure con tinker:

```bash
php artisan tinker --execute='$s = App\Models\Site::withoutTenancy()->where("domain","slimcms.it")->sole(); tenancy()->initialize($s->tenant_id); $s->useAsCurrent(); $s->forceFill(["shop_attivo"=>true])->save(); App\Models\Prodotto::create(["nome"=>"Prova","slug"=>"prova","descrizione"=>"Prodotto di prova.","prezzo"=>3900,"prezzo_barrato"=>4500,"scorte"=>3,"status"=>"published","blocks"=>[]]);'
```

(Regola 2 di CLAUDE.md: in console il tenant si inizializza esplicitamente.) Poi:

```bash
slimcms deploy-frontend --dry-run
```

Expected: build riuscita; `dist/prodotti/prova/index.html` esiste; contiene `"@type":"Product"`, `39,00`, `45,00`, `IVA inclusa`; `dist/og/prodotti/prova.png` esiste; gate CSS, immagini e Open Graph verdi. Controlla anche `grep -c prova dist/ricerca-indice.json` ≥ 1.

Poi **togli il prodotto di prova e rispegni il negozio** su slimcms.it in sviluppo (`php artisan db:seed` ricostruisce tutto), perché il sito vero non vende niente.

- [ ] **Step 8: Commit**

```bash
git add frontend/src
git commit -m "Le schede prodotto sul sito statico, con JSON-LD Product"
git push
```

Il deploy del frontend si fa alla fine del Task 6, insieme al gate aggiornato.

---

### Task 6: Gate di deploy, documentazione, rilascio

**Files:**
- Modify: `scripts/deploy-frontend.sh` (dopo il controllo della sitemap, riga ~249)
- Modify: `CLAUDE.md` (nuova sezione «Negozio» dopo «Blog»)

- [ ] **Step 1: Il gate**

In `scripts/deploy-frontend.sh`, subito dopo `echo "    sitemap.xml: ..."`, inserisci:

```bash
# Ogni scheda prodotto deve portare il suo JSON-LD Product: e' quello che fa
# comparire prezzo e disponibilita' nei risultati di ricerca. Una scheda senza
# e' una pagina valida, della giusta dimensione, che nei motori non vende.
if [[ -d dist/prodotti ]]; then
  schede=0
  shopt -s nullglob
  for scheda in dist/prodotti/*/index.html; do
    grep -q '"@type":"Product"' "$scheda" \
      || errore "$scheda non contiene il JSON-LD Product."
    schede=$((schede + 1))
  done
  shopt -u nullglob
  echo "    negozio: $schede schede prodotto, tutte con JSON-LD Product"
fi
```

Verifica: con il prodotto di prova del Task 5 ancora nel database di sviluppo, `scripts/deploy-frontend.sh --dry-run` stampa `negozio: 1 schede prodotto`. Senza prodotti la riga non compare e il gate passa.

- [ ] **Step 2: CLAUDE.md**

Aggiungi dopo la sezione «Blog»:

```markdown
## Negozio (SlimShop)

Specifica: `docs/superpowers/specs/2026-09-21-slimshop-design.md`. Si costruisce a passi;
il passo 1 (catalogo) è fatto.

**Si accende dal control plane** (`sites.shop_attivo`, sezione «Moduli» in `/manage/sites`):
il sito decide *come* usa il negozio, la piattaforma *se* ce l'ha. A negozio spento la voce
«Prodotti» sparisce e la sua URL risponde 403 (`ProdottoResource::canAccess()`), l'API di
build restituisce un elenco **vuoto** — non un 404, perché la build di ogni sito lo chiama —
e la sitemap non elenca schede. Spegnere non cancella niente.

**Gli importi sono interi in centesimi, IVA inclusa.** Il form li scrive in euro
(«39,90») e `App\Support\Euro` è l'unico punto di conversione: il campo `numeric` di
Filament rifiuterebbe la virgola, e un float nel database sbaglierebbe i conti.

**L'API espone `disponibile`, mai `scorte`.** Quanti pezzi ha un cliente in magazzino non
è un dato da scrivere nell'HTML di un sito pubblico; un test lo fissa.

Le schede stanno in `/prodotti/<slug>/` (segmento fisso), con JSON-LD `Product` + `Offer`
(`grafoProdottoJsonLd`, prezzo in decimale come vuole Schema.org, venditore = il sito) e
immagine Open Graph in `/og/prodotti/`. Il gate di deploy verifica che ogni scheda porti
il suo `Product`.

Il page builder è condiviso da pagine, articoli e prodotti: i blocchi con immagini
dichiarano `?HasMedia $record`, non `?Page`. Filament passa il record **per nome**, e con
`?Page` una galleria dentro un articolo o un prodotto era un `TypeError`. La risoluzione
dei blocchi per l'API sta in `RisolveBlocchi`, condiviso da `PageResource` e
`ProdottoResource`.
```

- [ ] **Step 3: Suite completa**

```bash
cd /home/claudio/dev/slimcms/backend
php artisan test
php artisan db:seed
```

Expected: tutto verde.

- [ ] **Step 4: Commit e rilascio**

```bash
cd /home/claudio/dev/slimcms
git add scripts/deploy-frontend.sh CLAUDE.md
git commit -m "Il gate verifica le schede prodotto, e CLAUDE.md racconta il negozio"
git push
slimcms deploy-backend
slimcms deploy-frontend
```

Expected: `slimcms.it` risponde 200; il negozio di slimcms.it è spento, quindi niente schede e nessuna riga «negozio» nel gate. La prima scheda vera arriva quando si accende il negozio su enneability.it: è una decisione dell'utente, non un passo di questo piano.

---

## Self-review

- **Copertura della specifica, passo 1:** §1 abilitazione dal control plane (Task 1), voce nascosta + 403 + API vuota + sitemap (Task 3, 4); §2 `prodotti` senza le colonne upsell, rimandate al passo 2 come dichiarato nei vincoli (Task 2); §3 pagina `/prodotti/<slug>/` in sitemap e ricerca, JSON-LD `Product`/`Offer` (Task 4, 5); §7 riga Prodotti con le soglie di ruolo (Task 2, 3); §9 test di `TenantScopeTest`, `PolicyRuoliTest`, `CicloDiVitaSitoTest` e gate (Task 1–6). Carrello, `/negozio.json`, pagine offerta/carrello/grazie: passo 2.
- **Nomi coerenti:** `negozioAttivo()`, `Prodotto::disponibile()`, `scopePubblicati`, `Euro::inCentesimi/daCentesimi`, `RisolveBlocchi::blocchiRisolti()`, `elencoProdotti()`, `euro()`, `grafoProdottoJsonLd(p, dominio, venditore)` sono usati con la stessa firma in tutti i task.
- **Verificati sul codice prima di consegnare il piano:** `BuildRequest.paths` (array, modello non scoped), `assertSchemaStateSet` presente in Filament, `Heroicon::OutlinedShoppingBag` presente, `slimcms deploy-frontend --dry-run` supportato, classi e variabili CSS globali in `Base.astro`.
