<?php

namespace Tests\Feature;

use App\Enums\Ruolo;
use App\Models\Page;
use App\Models\Plan;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * L'anteprima di una pagina, bozze comprese.
 *
 * Serve perche' una bozza non esiste sul sito: il sito e' statico e contiene
 * solo cio' che e' pubblicato. Il rendering e' un secondo renderer rispetto
 * ad Astro, e `ContrattoBlocchiTest` pretende che copra tutti i blocchi; qui
 * si verifica il resto — chi puo' aprirla e cosa ci finisce dentro.
 */
class AnteprimaTest extends TestCase
{
    use RefreshDatabase;

    private Site $sitoA;
    private Site $sitoB;
    private Page $bozza;

    protected function setUp(): void
    {
        parent::setUp();

        $piano = Plan::create(['name' => 'T', 'price_monthly' => 0, 'max_sites' => 5, 'max_storage_gb' => 1]);
        $tenant = Tenant::create(['id' => 'c', 'name' => 'C', 'slug' => 'c', 'status' => 'active', 'plan_id' => $piano->id]);

        $this->sitoA = Site::withoutTenancy()->create(['tenant_id' => $tenant->id, 'domain' => 'a.test', 'name' => 'A']);
        $this->sitoB = Site::withoutTenancy()->create(['tenant_id' => $tenant->id, 'domain' => 'b.test', 'name' => 'B']);

        $this->sitoA->useAsCurrent();
        Page::create(['title' => 'Home', 'slug' => 'home', 'is_home' => true, 'status' => 'published']);
        $this->bozza = Page::create([
            'title' => 'Mai pubblicata',
            'slug' => 'mai-pubblicata',
            'status' => 'draft',
            'blocks' => [
                ['type' => 'hero', 'data' => ['occhiello' => 'Occhiello', 'titolo' => 'Titolo di prova', 'testo' => 'Sommario']],
                ['type' => 'citazione', 'data' => ['testo' => 'Una frase memorabile', 'autore' => 'Chi l\'ha detta']],
            ],
        ]);
        Site::forgetCurrent();

        // La home del sito, da cui l'anteprima pesca i fogli di stile veri.
        Http::fake([
            'https://a.test/' => Http::response(
                '<html><head><link rel="stylesheet" href="/_astro/Base.abc.css">'
                . '<link rel="stylesheet" href="https://cattivo.test/x.css"></head><body></body></html>'
            ),
            'https://b.test/' => Http::response('', 404),
        ]);
    }

    private function entra(Ruolo $ruolo, ?Site $sito = null): User
    {
        $u = User::withoutSitePivotScope()->create([
            'name' => 'U', 'email' => $ruolo->value . uniqid() . '@c.test', 'password' => bcrypt('x'),
        ]);
        $u->sites()->attach($sito ?? $this->sitoA, ['role' => $ruolo->value]);
        $this->actingAs($u);

        return $u;
    }

    private function url(?Page $pagina = null): string
    {
        return route('anteprima.pagina', ['pagina' => ($pagina ?? $this->bozza)->getKey()]);
    }

    public function test_una_bozza_si_vede_in_anteprima(): void
    {
        $this->entra(Ruolo::Editor);

        $this->get($this->url())
            ->assertOk()
            ->assertSee('Titolo di prova', false)
            ->assertSee('Una frase memorabile', false)
            ->assertSee('Bozza');
    }

    /** Un'anteprima e' contenuto non pubblicato: non deve finire in nessun indice. */
    public function test_l_anteprima_e_noindex(): void
    {
        $this->entra(Ruolo::Editor);

        $this->get($this->url())->assertSee('noindex, nofollow', false);
    }

    /**
     * I fogli di stile sono quelli veri del sito, non una copia: e' la
     * differenza fra un'anteprima che assomiglia al sito e una che gli
     * somigliava sei mesi fa.
     */
    public function test_carica_i_fogli_di_stile_veri_del_sito(): void
    {
        $this->entra(Ruolo::Editor);

        $this->get($this->url())->assertSee('https://a.test/_astro/Base.abc.css', false);
    }

    /** Un `<link>` verso un dominio qualsiasi non va caricato dentro il pannello. */
    public function test_non_carica_fogli_di_altri_domini(): void
    {
        $this->entra(Ruolo::Editor);

        $this->get($this->url())->assertDontSee('cattivo.test', false);
    }

    public function test_un_sito_mai_pubblicato_lo_dice(): void
    {
        $this->sitoB->useAsCurrent();
        $pagina = Page::create(['title' => 'Prima pagina', 'slug' => 'prima', 'is_home' => true]);
        Site::forgetCurrent();

        $this->entra(Ruolo::Editor, $this->sitoB);

        $this->get($this->url($pagina))
            ->assertOk()
            ->assertSee('non è ancora pubblicato', false);
    }

    // -------------------------------------------------------- chi puo' aprirla

    public function test_chi_non_ha_accesso_al_sito_non_vede_l_anteprima(): void
    {
        $this->entra(Ruolo::Admin, $this->sitoB);

        $this->get($this->url())->assertForbidden();
    }

    public function test_senza_autenticazione_si_finisce_al_login(): void
    {
        $this->get($this->url())->assertRedirect();
    }

    /**
     * Anche chi e' in sola lettura puo' guardare: e' esattamente cio' che
     * quel ruolo promette.
     */
    public function test_un_lettore_vede_l_anteprima(): void
    {
        $this->entra(Ruolo::Viewer);

        $this->get($this->url())->assertOk();
    }

    public function test_una_pagina_inesistente_da_404(): void
    {
        $this->entra(Ruolo::Editor);

        $this->get(route('anteprima.pagina', ['pagina' => 999999]))->assertNotFound();
    }
}
