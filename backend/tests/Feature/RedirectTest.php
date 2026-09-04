<?php

namespace Tests\Feature;

use App\Filament\Resources\Redirects\Pages\CreateRedirect;
use App\Models\Page;
use App\Models\Plan;
use App\Models\Redirect;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class RedirectTest extends TestCase
{
    use RefreshDatabase;

    private Site $sitoA;
    private User $redattore;
    private Site $sitoB;

    protected function setUp(): void
    {
        parent::setUp();

        $piano = Plan::create(['name' => 'T', 'price_monthly' => 0, 'max_sites' => 5, 'max_storage_gb' => 1]);
        $tenant = Tenant::create(['id' => 'c', 'name' => 'C', 'slug' => 'c', 'status' => 'active', 'plan_id' => $piano->id]);

        $this->sitoA = Site::withoutTenancy()->create(['tenant_id' => $tenant->id, 'domain' => 'a.test', 'name' => 'A']);
        $this->sitoB = Site::withoutTenancy()->create(['tenant_id' => $tenant->id, 'domain' => 'b.test', 'name' => 'B']);

        $this->redattore = $redattore = User::withoutSitePivotScope()->create(['name' => 'R', 'email' => 'r@r.it', 'password' => bcrypt('x')]);
        $redattore->sites()->attach($this->sitoA, ['role' => 'editor']);

        $this->actingAs($redattore);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($this->sitoA, isQuiet: true);
        $this->sitoA->useAsCurrent();
    }

    public function test_il_percorso_viene_normalizzato(): void
    {
        // Chi incolla l'indirizzo intero non ha sbagliato: e' quello che ha
        // sotto gli occhi in Search Console.
        $this->assertSame('/vecchia', Redirect::normalizza('vecchia'));
        $this->assertSame('/vecchia', Redirect::normalizza('/vecchia/'));
        $this->assertSame('/vecchia', Redirect::normalizza('https://a.test/vecchia/'));
        $this->assertSame('/a/b', Redirect::normalizza('//a//b//'));
    }

    public function test_si_crea_dal_pannello_e_il_percorso_arriva_normalizzato(): void
    {
        Livewire::test(CreateRedirect::class)
            ->fillForm(['da' => 'https://a.test/vecchia/', 'a' => '/nuova/', 'codice' => 301, 'attivo' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('/vecchia', Redirect::first()->da);
    }

    public function test_un_rimando_a_se_stesso_viene_rifiutato(): void
    {
        Livewire::test(CreateRedirect::class)
            ->fillForm(['da' => '/x', 'a' => '/x', 'codice' => 301])
            ->call('create')
            ->assertHasFormErrors(['a']);
    }

    public function test_uno_spazio_nel_percorso_viene_rifiutato(): void
    {
        // Un .htaccess malformato fa rispondere 500 su TUTTO il sito.
        Livewire::test(CreateRedirect::class)
            ->fillForm(['da' => '/con spazio', 'a' => '/x/', 'codice' => 301])
            ->call('create')
            ->assertHasFormErrors(['da']);
    }

    public function test_una_destinazione_senza_schema_viene_rifiutata(): void
    {
        Livewire::test(CreateRedirect::class)
            ->fillForm(['da' => '/x', 'a' => 'esempio.it/y', 'codice' => 301])
            ->call('create')
            ->assertHasFormErrors(['a']);
    }

    public function test_lo_stesso_percorso_resta_libero_sugli_altri_siti(): void
    {
        Redirect::create(['da' => '/offerte', 'a' => '/nuove/', 'codice' => 301]);

        // Senza il where sul sito nella regola unique, un redirect /offerte di
        // un cliente bloccherebbe lo stesso percorso su tutti gli altri.
        $this->sitoB->useAsCurrent();
        Filament::setTenant($this->sitoB, isQuiet: true);

        Livewire::test(CreateRedirect::class)
            ->fillForm(['da' => '/offerte', 'a' => '/altro/', 'codice' => 301])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(1, Redirect::count());
    }

    public function test_un_sito_non_vede_i_redirect_di_un_altro(): void
    {
        Redirect::create(['da' => '/solo-di-a', 'a' => '/x/', 'codice' => 301]);

        $this->sitoB->useAsCurrent();

        $this->assertSame(0, Redirect::count());
    }

    public function test_l_api_consegna_il_file_compilato(): void
    {
        Redirect::create(['da' => '/vecchia', 'a' => '/nuova/', 'codice' => 301]);

        // Sanctum::actingAs e non un Bearer a mano: il setUp ha gia'
        // autenticato una SESSIONE, e in quel caso Sanctum attacca all'utente
        // un token transitorio che consente OGNI abilita'. Un test dei
        // permessi scritto con actingAs normale passerebbe sempre, anche con
        // il controllo tolto: darebbe falsa sicurezza sulla cosa piu'
        // delicata che abbiamo.
        Sanctum::actingAs($this->redattore, ["site:{$this->sitoA->id}"]);

        $risposta = $this->getJson("/api/sites/{$this->sitoA->domain}/htaccess");

        $risposta->assertOk();
        $risposta->assertSee('RewriteRule ^vecchia/?$ /nuova/ [R=301,L]', escape: false);
        $risposta->assertSee('ErrorDocument 404 /slimcms-404.php', escape: false);
    }

    public function test_un_token_di_un_altro_sito_non_legge_il_file(): void
    {
        Sanctum::actingAs($this->redattore, ["site:{$this->sitoB->id}"]);

        // Se questo passasse non sarebbe un difetto del solo .htaccess:
        // sarebbe la stessa falla su OGNI rotta, cioe' i contenuti di un
        // cliente leggibili con il token di un altro.
        $this->getJson("/api/sites/{$this->sitoA->domain}/pages")->assertForbidden();
        $this->getJson("/api/sites/{$this->sitoA->domain}/htaccess")->assertForbidden();
    }

    public function test_il_pannello_avverte_se_esiste_gia_una_pagina(): void
    {
        Page::create(['title' => 'Chi siamo', 'slug' => 'chi-siamo', 'blocks' => []]);

        // Non e' un errore bloccante: la regola resta scritta e semplicemente
        // non scatta, perche' il .htaccess controlla se la pagina esiste. Ma
        // chi la crea deve saperlo subito.
        Livewire::test(CreateRedirect::class)
            ->fillForm(['da' => '/chi-siamo', 'a' => '/altro/', 'codice' => 301])
            ->assertSee('esiste gia una pagina qui');
    }
}
