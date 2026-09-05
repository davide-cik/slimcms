<?php

namespace Tests\Feature;

use App\Enums\Ruolo;
use App\Filament\Pages\Statistiche;
use App\Models\Plan;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vista;
use App\Models\VistaImpronta;
use App\Support\ClassificatoreAgente;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Le statistiche di accesso, dal contatore sul sito alla pagina del pannello.
 *
 * Il sito e' statico: una visita non passa da nessun programma nostro. Il
 * contatore in PHP sul dominio del sito annota, questo importa. **Non si
 * leggono i log del server.**
 */
class StatisticheTest extends TestCase
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

        $this->registro = sys_get_temp_dir() . '/slimcms-viste-test-' . uniqid() . '.jsonl';
        config(['slimcms.registro_viste' => $this->registro]);
    }

    protected function tearDown(): void
    {
        @unlink($this->registro);
        foreach (glob($this->registro . '.*') ?: [] as $f) {
            @unlink($f);
        }

        parent::tearDown();
    }

    private function annota(array $righe): void
    {
        file_put_contents(
            $this->registro,
            collect($righe)->map(fn ($r) => json_encode($r))->implode("\n") . "\n"
        );
    }

    private const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
    private const GPTBOT = 'Mozilla/5.0 (compatible; GPTBot/1.2; +https://openai.com/gptbot)';
    private const GOOGLE = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
    private const SCANNER = 'Mozilla/5.0 (l9scan/2.0.733; +https://leakix.net)';

    public function test_ogni_categoria_finisce_al_posto_giusto(): void
    {
        $this->annota([
            ['p' => '/', 'u' => self::CHROME, 'i' => '1.2.3.4', 'e' => 'v', 'q' => '2026-09-05T10:00:00+00:00'],
            ['p' => '/', 'u' => self::GPTBOT, 'i' => '5.6.7.8', 'e' => 'v', 'q' => '2026-09-05T10:01:00+00:00'],
            ['p' => '/', 'u' => self::GOOGLE, 'i' => '9.9.9.9', 'e' => 'v', 'q' => '2026-09-05T10:02:00+00:00'],
            ['p' => '/', 'u' => self::SCANNER, 'i' => '4.4.4.4', 'e' => 'v', 'q' => '2026-09-05T10:03:00+00:00'],
        ]);

        $this->artisan('slimcms:importa-viste')->assertSuccessful();

        $per = Vista::withoutSiteScope()->pluck('conteggio', 'categoria');

        $this->assertSame(1, $per[ClassificatoreAgente::UMANO]);
        $this->assertSame(1, $per[ClassificatoreAgente::AI]);
        $this->assertSame(1, $per[ClassificatoreAgente::MOTORE]);
        $this->assertSame(1, $per[ClassificatoreAgente::BOT]);
    }

    /**
     * Una persona manda due segnali: il pixel e la conferma JavaScript.
     * Contarli entrambi come visite raddoppierebbe ogni visitatore.
     */
    public function test_la_conferma_javascript_non_e_una_visita(): void
    {
        $this->annota([
            ['p' => '/', 'u' => self::CHROME, 'i' => '1.2.3.4', 'e' => 'v', 'q' => '2026-09-05T10:00:00+00:00'],
            ['p' => '/', 'u' => self::CHROME, 'i' => '1.2.3.4', 'e' => 'j', 'q' => '2026-09-05T10:00:01+00:00'],
        ]);

        $this->artisan('slimcms:importa-viste')->assertSuccessful();

        $riga = Vista::withoutSiteScope()->sole();

        $this->assertSame(1, $riga->conteggio, 'La visita e\' stata contata due volte.');
        $this->assertSame(1, $riga->con_js);
    }

    public function test_le_persone_distinte_si_contano_una_volta_sola(): void
    {
        $this->annota([
            ['p' => '/', 'u' => self::CHROME, 'i' => '1.2.3.4', 'e' => 'v', 'q' => '2026-09-05T10:00:00+00:00'],
            ['p' => '/altra/', 'u' => self::CHROME, 'i' => '1.2.3.4', 'e' => 'v', 'q' => '2026-09-05T10:05:00+00:00'],
            ['p' => '/', 'u' => self::CHROME, 'i' => '9.8.7.6', 'e' => 'v', 'q' => '2026-09-05T11:00:00+00:00'],
            // Un crawler che passa da mille indirizzi non e' mille
            // visitatori: sommarlo renderebbe il numero inutile.
            ['p' => '/', 'u' => self::GPTBOT, 'i' => '5.5.5.5', 'e' => 'v', 'q' => '2026-09-05T11:30:00+00:00'],
        ]);

        $this->artisan('slimcms:importa-viste')->assertSuccessful();

        $this->assertSame(2, VistaImpronta::withoutSiteScope()->count());
    }

    /** Nessun indirizzo IP finisce nel database. */
    public function test_l_indirizzo_ip_non_viene_conservato(): void
    {
        $this->annota([
            ['p' => '/', 'u' => self::CHROME, 'i' => '203.0.113.42', 'e' => 'v', 'q' => '2026-09-05T10:00:00+00:00'],
        ]);

        $this->artisan('slimcms:importa-viste')->assertSuccessful();

        foreach (VistaImpronta::withoutSiteScope()->get() as $riga) {
            $this->assertStringNotContainsString('203.0.113', $riga->impronta);
        }

        $tutto = json_encode(Vista::withoutSiteScope()->get()->toArray())
            . json_encode(VistaImpronta::withoutSiteScope()->get()->toArray());

        $this->assertStringNotContainsString('203.0.113.42', $tutto);
    }

    /**
     * Il file viene consumato: rinominato, letto, cancellato. Cosi' non serve
     * ricordare dove si era arrivati — tenere una posizione di lettura e' il
     * modo tipico in cui un monitor muore dopo una rotazione.
     */
    public function test_il_registro_viene_consumato(): void
    {
        $this->annota([['p' => '/', 'u' => self::CHROME, 'i' => '1.1.1.1', 'e' => 'v', 'q' => '2026-09-05T10:00:00+00:00']]);

        $this->artisan('slimcms:importa-viste')->assertSuccessful();

        $this->assertFileDoesNotExist($this->registro);
        $this->assertSame([], glob($this->registro . '.*') ?: []);
    }

    public function test_due_passate_sommano_invece_di_sostituire(): void
    {
        foreach ([1, 2] as $_) {
            $this->annota([['p' => '/', 'u' => self::CHROME, 'i' => '1.1.1.1', 'e' => 'v', 'q' => '2026-09-05T10:00:00+00:00']]);
            $this->artisan('slimcms:importa-viste')->assertSuccessful();
        }

        $this->assertSame(2, Vista::withoutSiteScope()->sole()->conteggio);
    }

    public function test_una_riga_troncata_non_ferma_l_importazione(): void
    {
        file_put_contents($this->registro,
            json_encode(['p' => '/', 'u' => self::CHROME, 'i' => '1.1.1.1', 'e' => 'v', 'q' => '2026-09-05T10:00:00+00:00']) . "\n"
            . '{"p":"/rotta","u":"Chr' . "\n");

        $this->artisan('slimcms:importa-viste')->assertSuccessful();

        $this->assertSame(1, Vista::withoutSiteScope()->count());
    }

    // ------------------------------------------------------- la pagina

    private function entra(Ruolo $ruolo): void
    {
        $u = User::withoutSitePivotScope()->create([
            'name' => 'U', 'email' => $ruolo->value . '@c.test', 'password' => bcrypt('x'),
        ]);
        $u->sites()->attach($this->sito, ['role' => $ruolo->value]);

        $this->actingAs($u);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($this->sito, isQuiet: true);
        $this->sito->useAsCurrent();
    }

    public function test_un_redattore_vede_le_statistiche(): void
    {
        $this->entra(Ruolo::Editor);

        Livewire::test(Statistiche::class)->assertOk();
    }

    public function test_un_autore_non_vede_le_statistiche(): void
    {
        $this->entra(Ruolo::Author);

        $this->assertFalse(Statistiche::canAccess());
    }

    public function test_le_statistiche_di_un_sito_non_si_vedono_da_un_altro(): void
    {
        $altro = Site::withoutTenancy()->create([
            'tenant_id' => $this->sito->tenant_id, 'domain' => 'altro.test', 'name' => 'Altro',
        ]);

        $altro->useAsCurrent();
        Vista::create(['giorno' => today(), 'categoria' => 'umano', 'agente' => 'Chrome', 'percorso' => '/segreta/', 'conteggio' => 99]);
        Site::forgetCurrent();

        $this->entra(Ruolo::Editor);

        $this->assertSame(0, Vista::query()->sum('conteggio'));
    }
}
