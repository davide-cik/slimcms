<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Page;
use App\Models\Plan;
use App\Models\Site;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * SiteIsolationTest
 *
 * Mentre TenantScopeTest verifica che i trait siano ATTACCATI ai modelli
 * giusti, questo verifica che lo scope FILTRI davvero. Sono due garanzie
 * diverse: la prima e' statica, la seconda comportamentale, e la seconda
 * e' quella che protegge i dati dei clienti.
 */
class SiteIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Site $siteA;
    private Site $siteB;

    protected function setUp(): void
    {
        parent::setUp();

        $plan = Plan::create([
            'name' => 'Test',
            'price_monthly' => 0,
            'max_sites' => 10,
            'max_storage_gb' => 1,
        ]);

        $tenantA = Tenant::create(['id' => 'tenant-a', 'name' => 'Cliente A', 'slug' => 'cliente-a', 'status' => 'active', 'plan_id' => $plan->id]);
        $tenantB = Tenant::create(['id' => 'tenant-b', 'name' => 'Cliente B', 'slug' => 'cliente-b', 'status' => 'active', 'plan_id' => $plan->id]);

        $this->siteA = Site::withoutTenancy()->create(['tenant_id' => $tenantA->id, 'domain' => 'a.test', 'name' => 'Sito A']);
        $this->siteB = Site::withoutTenancy()->create(['tenant_id' => $tenantB->id, 'domain' => 'b.test', 'name' => 'Sito B']);

        $this->siteA->useAsCurrent();
        Page::create(['title' => 'Pagina di A', 'slug' => 'pagina-a']);

        $this->siteB->useAsCurrent();
        Page::create(['title' => 'Pagina di B', 'slug' => 'pagina-b']);

        Site::forgetCurrent();
    }

    public function test_un_sito_non_vede_le_pagine_di_un_altro(): void
    {
        $this->siteA->useAsCurrent();

        $this->assertSame(1, Page::count());
        $this->assertSame('pagina-a', Page::first()->slug);
        $this->assertFalse(
            Page::where('slug', 'pagina-b')->exists(),
            'Il sito A vede una pagina del sito B: il global scope non sta filtrando.'
        );
    }

    public function test_il_site_id_viene_assegnato_dal_contesto_corrente(): void
    {
        $this->siteB->useAsCurrent();

        $pagina = Page::create(['title' => 'Nuova', 'slug' => 'nuova']);

        $this->assertSame($this->siteB->id, $pagina->site_id);
    }

    public function test_creare_una_pagina_senza_contesto_solleva_eccezione(): void
    {
        Site::forgetCurrent();

        $this->expectException(RuntimeException::class);

        // Senza questo comportamento la riga verrebbe inserita con site_id NULL:
        // invisibile a ogni query scoped, quindi un errore che nessuno nota.
        Page::create(['title' => 'Orfana', 'slug' => 'orfana']);
    }

    /**
     * Regressione su una trappola reale di Laravel, trovata in sviluppo.
     *
     * Builder::forceDelete() esegue $this->query->delete() sul query builder
     * GREZZO, senza passare da applyScopes(): i global scope NON vengono
     * applicati e la delete attraversa tutti i tenant. Builder::delete()
     * invece passa da toBase() -> applyScopes() ed e' correttamente scoped.
     *
     * Questo test fissa la differenza, cosi' se un domani si aggiungesse un
     * override di forceDelete() sbagliato ce ne accorgiamo qui.
     */
    public function test_la_delete_scoped_non_tocca_le_pagine_di_altri_siti(): void
    {
        $this->siteA->useAsCurrent();
        Page::query()->delete();

        $this->assertSame(0, Page::count(), 'Le pagine di A dovevano essere cancellate.');

        $this->siteB->useAsCurrent();
        $this->assertSame(1, Page::count(), 'La delete su A ha toccato anche le pagine di B.');
    }

    public function test_force_delete_su_istanza_cancella_solo_quella_riga(): void
    {
        $this->siteA->useAsCurrent();
        Page::first()->forceDelete();

        $this->assertSame(0, Page::withTrashed()->count());

        $this->siteB->useAsCurrent();
        $this->assertSame(1, Page::withTrashed()->count(), 'Il forceDelete su istanza ha superato il confine del sito.');
    }

    /**
     * I media di Spatie sono il caso piu' insidioso: la sua tabella e' solo
     * polimorfa e senza la colonna site_id che abbiamo aggiunto sarebbe
     * completamente fuori dal global scope, con i file di un cliente
     * elencabili da un altro.
     */
    public function test_i_media_di_un_sito_non_sono_visibili_da_un_altro(): void
    {
        $this->siteA->useAsCurrent();
        $mediaA = $this->creaMedia($this->siteA, 'foto-di-a.jpg');

        $this->siteB->useAsCurrent();
        $this->creaMedia($this->siteB, 'riservato-di-b.jpg');

        $this->siteA->useAsCurrent();
        $this->assertSame(1, Media::count());
        $this->assertSame('foto-di-a.jpg', Media::first()->file_name);
        $this->assertFalse(
            Media::where('file_name', 'riservato-di-b.jpg')->exists(),
            'Il sito A vede un file del sito B: i media sono fuori dal global scope.'
        );

        $this->assertSame($this->siteA->id, $mediaA->site_id);
    }

    private function creaMedia(\App\Models\Site $site, string $fileName): Media
    {
        return Media::create([
            'model_type' => $site->getMorphClass(),
            'model_id' => $site->getKey(),
            'collection_name' => 'libreria',
            'name' => pathinfo($fileName, PATHINFO_FILENAME),
            'file_name' => $fileName,
            'mime_type' => 'image/jpeg',
            'disk' => 'media',
            'size' => 1024,
            'manipulations' => [],
            'custom_properties' => [],
            'generated_conversions' => [],
            'responsive_images' => [],
        ]);
    }

    public function test_il_sito_e_scoped_per_tenant(): void
    {
        tenancy()->initialize($this->siteB->tenant);

        $this->assertSame(1, Site::count());
        $this->assertFalse(
            Site::where('domain', 'a.test')->exists(),
            'Il tenant B vede il sito del tenant A: lo scope su Site non sta filtrando.'
        );

        tenancy()->end();
    }
}
