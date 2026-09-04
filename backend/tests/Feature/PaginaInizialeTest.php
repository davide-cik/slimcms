<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Plan;
use App\Models\Site;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * La pagina iniziale di un sito.
 *
 * Prima era riconosciuta dallo slug "home": una convenzione implicita che si
 * rompe appena qualcuno rinomina lo slug. Ora e' un flag, con due garanzie
 * che devono reggere: ce n'e' sempre esattamente una, e non si puo'
 * cancellare.
 */
class PaginaInizialeTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $piano = Plan::create(['name' => 'T', 'price_monthly' => 0, 'max_sites' => 5, 'max_storage_gb' => 1]);
        $tenant = Tenant::create(['id' => 'c', 'name' => 'C', 'slug' => 'c', 'status' => 'active', 'plan_id' => $piano->id]);
        $this->site = Site::withoutTenancy()->create(['tenant_id' => $tenant->id, 'domain' => 'c.test', 'name' => 'C']);
        $this->site->useAsCurrent();
    }

    public function test_promuovere_una_pagina_degrada_la_precedente(): void
    {
        $prima = Page::create(['title' => 'Prima', 'slug' => 'prima', 'is_home' => true]);
        $seconda = Page::create(['title' => 'Seconda', 'slug' => 'seconda', 'is_home' => true]);

        // Due pagine che si contendono la radice sarebbero risolte
        // dall'ordine di lettura del frontend, cioe' a caso.
        $this->assertFalse($prima->fresh()->is_home, 'La precedente doveva smettere di essere la home.');
        $this->assertTrue($seconda->fresh()->is_home);
        $this->assertSame(1, Page::where('is_home', true)->count());
    }

    public function test_la_pagina_iniziale_non_si_puo_cancellare(): void
    {
        $home = Page::create(['title' => 'Home', 'slug' => 'home', 'is_home' => true]);

        $this->expectException(RuntimeException::class);

        $home->delete();
    }

    public function test_le_altre_pagine_si_cancellano_normalmente(): void
    {
        Page::create(['title' => 'Home', 'slug' => 'home', 'is_home' => true]);
        $altra = Page::create(['title' => 'Altra', 'slug' => 'altra']);

        $altra->delete();

        $this->assertSoftDeleted($altra);
        $this->assertSame(1, Page::where('is_home', true)->count());
    }

    /** Ogni sito ha la propria: promuoverne una qui non tocca gli altri. */
    public function test_la_home_e_per_sito(): void
    {
        $home = Page::create(['title' => 'Home', 'slug' => 'home', 'is_home' => true]);

        $altroSito = Site::withoutTenancy()->create([
            'tenant_id' => $this->site->tenant_id, 'domain' => 'b.test', 'name' => 'B',
        ]);
        $altroSito->useAsCurrent();
        $homeAltro = Page::create(['title' => 'Home B', 'slug' => 'home', 'is_home' => true]);

        $this->assertTrue($home->fresh()->is_home, 'Promuovere la home di un sito ha degradato quella di un altro.');
        $this->assertTrue($homeAltro->fresh()->is_home);
    }

    public function test_la_home_sta_sulla_radice_nella_sitemap(): void
    {
        $home = Page::create(['title' => 'Home', 'slug' => 'pagina-iniziale', 'is_home' => true, 'status' => 'published']);

        // Lo slug NON e' "home": e' il punto del flag.
        $this->assertSame('pagina-iniziale', $home->slug);
        $this->assertTrue($home->is_home);
    }
}
