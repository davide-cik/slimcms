<?php

namespace Tests\Feature;

use App\ControlPlane\Filament\Resources\Sites\Pages\ListSites;
use App\ControlPlane\Models\AdminUser;
use App\Enums\StatoSito;
use App\Models\BuildRequest;
use App\Models\Plan;
use App\Models\Redirect;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Services\GeneratoreHtaccess;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Sospendere e parcheggiare un sito.
 *
 * Il sito e' statico, quindi "fermarlo" non puo' essere un controllo in
 * Laravel: e' una riga di `.htaccess` che manda tutto sulla pagina di
 * cortesia con 503. Verificato su Apache 2.4 di questo server prima di
 * scriverlo — `[R=503]` risponde 503 servendo l'ErrorDocument, senza redirect
 * e senza anelli.
 */
class SospensioneSitoTest extends TestCase
{
    use RefreshDatabase;

    private Site $sito;

    protected function setUp(): void
    {
        parent::setUp();

        $piano = Plan::create(['name' => 'T', 'price_monthly' => 0, 'max_sites' => 5, 'max_storage_gb' => 1]);
        $tenant = Tenant::create(['id' => 'c', 'name' => 'C', 'slug' => 'c', 'status' => 'active', 'plan_id' => $piano->id]);
        $this->sito = Site::withoutTenancy()->create(['tenant_id' => $tenant->id, 'domain' => 'c.test', 'name' => 'C']);
    }

    private function htaccess(): string
    {
        $u = User::withoutSitePivotScope()->create(['name' => 'R', 'email' => 'r@c.test', 'password' => bcrypt('x')]);
        $u->sites()->attach($this->sito, ['role' => 'admin']);
        Sanctum::actingAs($u, ['site:' . $this->sito->id]);

        return $this->get("/api/sites/{$this->sito->domain}/htaccess")->assertOk()->getContent();
    }

    public function test_un_sito_nasce_attivo(): void
    {
        $this->assertSame(StatoSito::Attivo, $this->sito->statoSito());
        $this->assertFalse($this->sito->mostraCortesia());
    }

    /**
     * Il cast enum farebbe esplodere la LETTURA del modello su un valore
     * inatteso: via il pannello, via l'API, via il sito. Uno stato che non si
     * riesce a leggere non deve spegnere il sito di un cliente.
     */
    public function test_uno_stato_illeggibile_non_spegne_il_sito(): void
    {
        \DB::table('sites')->where('id', $this->sito->id)->update(['stato' => 'valore-mai-visto']);

        $riletto = Site::withoutTenancy()->find($this->sito->id);

        $this->assertSame(StatoSito::Attivo, $riletto->statoSito());
        $this->assertFalse($riletto->mostraCortesia());
    }

    public function test_un_sito_attivo_serve_i_suoi_contenuti(): void
    {
        $h = $this->htaccess();

        $this->assertStringContainsString('ErrorDocument 404', $h);
        $this->assertStringNotContainsString('503', $h);
    }

    public function test_un_sito_sospeso_risponde_con_la_pagina_di_cortesia(): void
    {
        $this->sito->forceFill(['stato' => StatoSito::Sospeso->value])->save();

        $h = $this->htaccess();

        $this->assertStringContainsString('ErrorDocument 503 /' . GeneratoreHtaccess::CORTESIA, $h);
        $this->assertStringContainsString('RewriteRule ^ - [R=503,L]', $h);

        // La condizione che esclude la pagina stessa: senza, l'ErrorDocument
        // verrebbe intercettato a sua volta e Apache risponderebbe 503 con un
        // corpo vuoto.
        $this->assertStringContainsString('RewriteCond %{REQUEST_URI} !^/slimcms', $h);
    }

    public function test_parcheggiato_si_comporta_come_sospeso(): void
    {
        $this->sito->forceFill(['stato' => StatoSito::Parcheggiato->value])->save();

        $this->assertStringContainsString('[R=503,L]', $this->htaccess());
    }

    /**
     * Un sito fermo non manda il visitatore da nessuna parte: lo stato viene
     * prima dei redirect.
     */
    public function test_un_sito_fermo_non_applica_i_redirect(): void
    {
        $this->sito->useAsCurrent();
        Redirect::create(['da' => '/vecchia', 'a' => '/nuova/', 'codice' => 301, 'attivo' => true]);
        Site::forgetCurrent();

        $this->sito->forceFill(['stato' => StatoSito::Sospeso->value])->save();

        $h = $this->htaccess();

        $this->assertStringNotContainsString('/vecchia', $h);
        $this->assertStringContainsString('[R=503,L]', $h);
    }

    /**
     * Cambiare stato deve accodare una build: e' quella che riscrive
     * l'`.htaccess`. Senza, si sospende un sito e resta online.
     */
    public function test_cambiare_stato_accoda_una_build(): void
    {
        BuildRequest::query()->delete();

        $this->sito->forceFill(['stato' => StatoSito::Sospeso->value])->save();

        $this->assertSame(1, BuildRequest::count());
    }

    // ------------------------------------------------- dal control plane

    public function test_si_sospende_e_si_riattiva_dall_elenco(): void
    {
        $admin = AdminUser::create([
            'name' => 'A', 'email' => 'a@a.it', 'password' => bcrypt('x'), 'role' => 'super-admin',
        ]);
        $this->actingAs($admin, 'manage');
        Filament::setCurrentPanel('manage');

        Livewire::test(ListSites::class)
            ->callAction(TestAction::make('ferma')->table($this->sito));

        $this->assertTrue($this->sito->fresh()->mostraCortesia());

        Livewire::test(ListSites::class)
            ->callAction(TestAction::make('ferma')->table($this->sito->fresh()));

        $this->assertFalse($this->sito->fresh()->mostraCortesia());
    }

    /** Lo stato e' della piattaforma: dal pannello del sito non si tocca. */
    public function test_lo_stato_non_si_cambia_dal_pannello_del_sito(): void
    {
        $sorgente = file_get_contents(
            app_path('Filament/Pages/Tenancy/ImpostazioniSito.php')
        );

        $this->assertStringNotContainsString("make('stato'", $sorgente);
        $this->assertStringNotContainsString("make('nota_cortesia'", $sorgente);
    }

    /**
     * La pagina di cortesia la legge un visitatore, non chi usa il pannello.
     *
     * Nel PHP di questo progetto gli accenti si omettono; queste stringhe
     * pero' attraversano il confine e finiscono su una pagina pubblica. La
     * prima sospensione vera ha mostrato "e' temporaneamente" a chiunque
     * passasse.
     */
    public function test_i_testi_visibili_al_visitatore_sono_in_italiano_vero(): void
    {
        foreach (StatoSito::cases() as $stato) {
            foreach ([$stato->titoloCortesia(), $stato->testoCortesia()] as $testo) {
                $this->assertDoesNotMatchRegularExpression(
                    "/\b(e|puo|piu|perche|cosi|gia|sara|verra)'/u",
                    $testo,
                    "«{$testo}» usa l'apostrofo al posto dell'accento su una pagina pubblica."
                );
            }
        }
    }
}
