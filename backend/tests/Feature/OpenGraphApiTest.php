<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Plan;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OpenGraphApiTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $piano = Plan::create(['name' => 'T', 'price_monthly' => 0, 'max_sites' => 5, 'max_storage_gb' => 1]);
        $tenant = Tenant::create(['id' => 'c', 'name' => 'C', 'slug' => 'c', 'status' => 'active', 'plan_id' => $piano->id]);

        $this->site = Site::withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'domain' => 'c.test',
            'name' => 'Cliente',
            'og_config' => ['payoff' => 'Il payoff', 'cta' => 'Visita il sito'],
        ]);

        $this->site->useAsCurrent();
        Page::create(['title' => 'Chi siamo', 'slug' => 'chi-siamo', 'status' => 'published']);
        Site::forgetCurrent();
    }

    private function autenticaPerIlSito(): void
    {
        $u = User::withoutSitePivotScope()->create(['name' => 'R', 'email' => 'r@r.it', 'password' => bcrypt('x')]);
        $u->sites()->attach($this->site, ['role' => 'admin']);
        Sanctum::actingAs($u, ['site:' . $this->site->id]);
    }

    private function dimensioni(string $png): array
    {
        return array_values(unpack('Nl/Na', substr($png, 16, 8)));
    }

    public function test_restituisce_un_png_per_una_pagina(): void
    {
        $this->autenticaPerIlSito();

        $r = $this->get("/api/sites/{$this->site->domain}/og/chi-siamo.png");

        $r->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame([1200, 1600], $this->dimensioni($r->getContent()));
    }

    public function test_il_ritaglio_ha_il_rapporto_dei_social(): void
    {
        $this->autenticaPerIlSito();

        $r = $this->get("/api/sites/{$this->site->domain}/og/chi-siamo.png?ritaglio=1");

        $this->assertSame([1200, 628], $this->dimensioni($r->getContent()));
    }

    /** Uno slug morto non deve rompere l'anteprima di un link vecchio. */
    public function test_uno_slug_inesistente_ricade_sull_immagine_del_sito(): void
    {
        $this->autenticaPerIlSito();

        $this->get("/api/sites/{$this->site->domain}/og/mai-esistita.png")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_serve_il_token(): void
    {
        $this->getJson("/api/sites/{$this->site->domain}/og.png")->assertUnauthorized();
    }

    /** Il token di un sito non deve poter leggere le immagini di un altro. */
    public function test_un_token_di_un_altro_sito_riceve_403(): void
    {
        $altro = Site::withoutTenancy()->create([
            'tenant_id' => $this->site->tenant_id, 'domain' => 'altro.test', 'name' => 'Altro',
        ]);

        $u = User::withoutSitePivotScope()->create(['name' => 'X', 'email' => 'x@x.it', 'password' => bcrypt('x')]);
        $u->sites()->attach($altro, ['role' => 'admin']);
        Sanctum::actingAs($u, ['site:' . $altro->id]);

        $this->getJson("/api/sites/{$this->site->domain}/og.png")->assertForbidden();
    }
}
