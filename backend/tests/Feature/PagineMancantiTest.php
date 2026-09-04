<?php

namespace Tests\Feature;

use App\Console\Commands\ImportaPagineMancanti;
use App\Models\PaginaMancante;
use App\Models\Plan;
use App\Models\Redirect;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Filament\Resources\PagineMancanti\Pages\ListPagineMancanti;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Il monitoraggio dei 404.
 *
 * Il sito e' statico e un 404 non tocca Laravel: la pagina d'errore e' un file
 * PHP sul dominio del sito che annota l'indirizzo in una cartella privata, e
 * un comando importa quelle righe. Qui si prova il pezzo che le importa.
 */
class PagineMancantiTest extends TestCase
{
    use RefreshDatabase;

    private Site $sito;
    private string $registro;

    protected function setUp(): void
    {
        parent::setUp();

        $piano = Plan::create(['name' => 'T', 'price_monthly' => 0, 'max_sites' => 5, 'max_storage_gb' => 1]);
        $tenant = Tenant::create(['id' => 'c', 'name' => 'C', 'slug' => 'c', 'status' => 'active', 'plan_id' => $piano->id]);
        $this->sito = Site::withoutTenancy()->create(['tenant_id' => $tenant->id, 'domain' => 'c.test', 'name' => 'C']);

        $cartella = storage_path('framework/testing/404');
        File::ensureDirectoryExists($cartella);
        $this->registro = "{$cartella}/{c.test}.jsonl";

        config(['slimcms.registro_404' => "{$cartella}/{dominio}.jsonl"]);
        $this->registro = "{$cartella}/c.test.jsonl";

        $this->sito->useAsCurrent();
    }

    protected function tearDown(): void
    {
        File::delete(File::glob(storage_path('framework/testing/404/*')));
        parent::tearDown();
    }

    private function annota(array $righe): void
    {
        File::put($this->registro, implode("\n", array_map(fn ($r) => json_encode($r), $righe)) . "\n");
    }

    public function test_i_colpi_si_aggregano_per_indirizzo(): void
    {
        $this->annota([
            ['p' => '/vecchia', 'r' => 'https://c.test/altra/', 'q' => '2026-09-04T20:10:00+00:00'],
            ['p' => '/vecchia', 'r' => 'https://c.test/altra/', 'q' => '2026-09-04T20:12:00+00:00'],
            ['p' => '/altra-ancora', 'r' => null, 'q' => '2026-09-04T20:13:00+00:00'],
        ]);

        $this->artisan('slimcms:importa-404')->assertSuccessful();

        $riga = PaginaMancante::where('percorso', '/vecchia')->first();

        $this->assertSame(2, $riga->colpi);
        $this->assertSame(2, $riga->colpi_con_referrer);
        $this->assertSame(2, PaginaMancante::count());
    }

    public function test_la_query_string_non_moltiplica_le_righe(): void
    {
        // /cerca?q=uno e /cerca?q=due sono lo stesso indirizzo mancante:
        // tenerli separati riempirebbe l'elenco di righe che dicono la
        // stessa cosa.
        $this->annota([
            ['p' => '/cerca?q=uno', 'r' => null, 'q' => '2026-09-04T20:10:00+00:00'],
            ['p' => '/cerca?q=due', 'r' => null, 'q' => '2026-09-04T20:11:00+00:00'],
        ]);

        $this->artisan('slimcms:importa-404')->assertSuccessful();

        $this->assertSame(1, PaginaMancante::count());
        $this->assertSame(2, PaginaMancante::first()->colpi);
    }

    public function test_una_riga_troncata_non_ferma_l_importazione(): void
    {
        // Il gestore scrive in append con LOCK_EX, ma un disco pieno o un
        // processo ucciso a meta' possono lasciare una riga monca: non deve
        // far perdere tutte le altre.
        File::put($this->registro, implode("\n", [
            json_encode(['p' => '/buona', 'r' => null, 'q' => '2026-09-04T20:10:00+00:00']),
            '{"p":"/tron',
            json_encode(['p' => '/altra-buona', 'r' => null, 'q' => '2026-09-04T20:11:00+00:00']),
        ]) . "\n");

        $this->artisan('slimcms:importa-404')->assertSuccessful();

        $this->assertSame(2, PaginaMancante::count());
    }

    public function test_due_importazioni_sommano_invece_di_sovrascrivere(): void
    {
        $this->annota([['p' => '/vecchia', 'r' => 'https://c.test/x/', 'q' => '2026-09-04T20:10:00+00:00']]);
        $this->artisan('slimcms:importa-404')->assertSuccessful();

        $this->annota([['p' => '/vecchia', 'r' => 'https://c.test/y/', 'q' => '2026-09-04T21:10:00+00:00']]);
        $this->artisan('slimcms:importa-404')->assertSuccessful();

        $riga = PaginaMancante::where('percorso', '/vecchia')->first();

        $this->assertSame(2, $riga->colpi);
        $this->assertSame('https://c.test/y/', $riga->ultimo_referrer, 'Doveva restare l\'ultimo collegamento visto.');
    }

    public function test_il_registro_viene_consumato(): void
    {
        // Se restasse, la passata successiva conterebbe di nuovo gli stessi
        // colpi e i numeri non vorrebbero piu' dire niente.
        $this->annota([['p' => '/x', 'r' => null, 'q' => '2026-09-04T20:10:00+00:00']]);

        $this->artisan('slimcms:importa-404')->assertSuccessful();
        $this->assertFileDoesNotExist($this->registro);

        $this->artisan('slimcms:importa-404')->assertSuccessful();
        $this->assertSame(1, PaginaMancante::first()->colpi);
    }

    public function test_la_prova_a_secco_non_consuma_niente(): void
    {
        $this->annota([['p' => '/x', 'r' => null, 'q' => '2026-09-04T20:10:00+00:00']]);

        $this->artisan('slimcms:importa-404', ['--secco' => true])->assertSuccessful();

        $this->assertFileExists($this->registro);
        $this->assertSame(0, PaginaMancante::count());
    }

    public function test_solo_quelli_con_un_collegamento_sono_da_guardare(): void
    {
        // Senza questo filtro l'elenco e' quasi tutto scanner che provano
        // /wp-admin, e diventa un allarme che si impara a ignorare.
        $this->annota([
            ['p' => '/collegamento-rotto', 'r' => 'https://altro.it/articolo', 'q' => '2026-09-04T20:10:00+00:00'],
            ['p' => '/wp-admin/', 'r' => null, 'q' => '2026-09-04T20:11:00+00:00'],
            ['p' => '/.env', 'r' => null, 'q' => '2026-09-04T20:12:00+00:00'],
        ]);

        $this->artisan('slimcms:importa-404')->assertSuccessful();

        $this->assertSame(3, PaginaMancante::count(), 'Va registrato tutto...');
        $this->assertSame(['/collegamento-rotto'], PaginaMancante::daGuardare()->pluck('percorso')->all());
    }

    public function test_un_sito_non_vede_le_pagine_mancanti_di_un_altro(): void
    {
        $this->annota([['p' => '/x', 'r' => null, 'q' => '2026-09-04T20:10:00+00:00']]);
        $this->artisan('slimcms:importa-404')->assertSuccessful();

        $altro = Site::withoutTenancy()->create([
            'tenant_id' => $this->sito->tenant_id, 'domain' => 'd.test', 'name' => 'D',
        ]);
        $altro->useAsCurrent();

        $this->assertSame(0, PaginaMancante::count());
    }

    public function test_dal_pannello_si_crea_un_redirect_dal_404(): void
    {
        $this->annota([['p' => '/vecchia', 'r' => 'https://c.test/x/', 'q' => '2026-09-04T20:10:00+00:00']]);
        $this->artisan('slimcms:importa-404')->assertSuccessful();

        $redattore = User::withoutSitePivotScope()->create(['name' => 'R', 'email' => 'r@r.it', 'password' => bcrypt('x')]);
        $redattore->sites()->attach($this->sito, ['role' => 'editor']);
        $this->actingAs($redattore);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($this->sito, isQuiet: true);

        Livewire::test(ListPagineMancanti::class)
            ->callAction(
                TestAction::make('reindirizza')->table(PaginaMancante::first()),
                ['a' => '/nuova/', 'codice' => 301]
            )
            ->assertHasNoActionErrors();

        $redirect = Redirect::first();

        $this->assertSame('/vecchia', $redirect->da);
        $this->assertSame('/nuova/', $redirect->a);
        // Sistemato: sparisce dall'elenco ma la riga resta, cosi' se il
        // redirect venisse tolto si rivede la storia.
        $this->assertTrue(PaginaMancante::first()->ignorata);
    }
}
