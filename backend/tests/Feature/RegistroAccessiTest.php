<?php

namespace Tests\Feature;

use App\ControlPlane\Models\AdminUser;
use App\Models\Accesso;
use App\Models\Plan;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Il registro degli accessi ai due pannelli.
 *
 * Risponde a una domanda sola: qualcuno sta provando a entrare che non
 * dovrebbe? Per rispondere servono anche i tentativi falliti, che per
 * definizione non hanno un utente.
 */
class RegistroAccessiTest extends TestCase
{
    use RefreshDatabase;

    private Site $sito;

    protected function setUp(): void
    {
        parent::setUp();

        $piano = Plan::create(['name' => 'T', 'price_monthly' => 0, 'max_sites' => 5, 'max_storage_gb' => 1]);
        $tenant = Tenant::create(['id' => 'c', 'name' => 'C', 'slug' => 'c', 'status' => 'active', 'plan_id' => $piano->id]);
        $this->sito = Site::withoutTenancy()->create(['tenant_id' => $tenant->id, 'domain' => 'c.test', 'name' => 'C']);

        Cache::flush();
        Mail::fake();
    }

    private function redattore(): User
    {
        $u = User::withoutSitePivotScope()->create([
            'name' => 'Giulia', 'email' => 'giulia@c.test', 'password' => bcrypt('segretissima'),
        ]);
        $u->sites()->attach($this->sito, ['role' => 'editor']);

        return $u;
    }

    /**
     * Si emette l'evento invece di usare `actingAs`: quella scorciatoia dei
     * test imposta l'utente sulla guardia e **non emette `Login`**, quindi un
     * test scritto cosi' non proverebbe niente di cio' che succede davvero.
     */
    public function test_un_accesso_riuscito_finisce_nel_registro(): void
    {
        Event::dispatch(new Login('web', $this->redattore(), false));

        $a = Accesso::sole();

        $this->assertSame(Accesso::RIUSCITO, $a->esito);
        $this->assertSame('giulia@c.test', $a->email);
        $this->assertSame('web', $a->guardia);
        $this->assertFalse($a->impersonato);
    }

    public function test_distingue_i_due_pannelli(): void
    {
        $admin = AdminUser::create([
            'name' => 'Super', 'email' => 'super@slimcms.it', 'password' => bcrypt('x'), 'role' => 'super-admin',
        ]);

        Event::dispatch(new Login('manage', $admin, false));

        $this->assertSame('manage', Accesso::sole()->guardia);
        $this->assertSame('Gestione piattaforma', Accesso::sole()->pannello());
    }

    /**
     * Il caso che conta: un tentativo fallito non ha un utente, ma l'email
     * tentata e' l'unica cosa che si sa di chi ha provato.
     */
    public function test_un_tentativo_fallito_conserva_l_email_anche_se_l_utente_non_esiste(): void
    {
        Event::dispatch(new Failed('web', null, ['email' => 'nessuno@example.com', 'password' => 'x']));

        $a = Accesso::sole();

        $this->assertSame(Accesso::FALLITO, $a->esito);
        $this->assertSame('nessuno@example.com', $a->email);
        $this->assertNull($a->utente_id);
    }

    public function test_registra_anche_il_blocco_per_troppi_tentativi(): void
    {
        Event::dispatch(new Lockout(Request::create('/admin/login', 'POST', ['email' => 'insistente@example.com'])));

        $this->assertSame(Accesso::BLOCCATO, Accesso::sole()->esito);
        $this->assertSame('insistente@example.com', Accesso::sole()->email);
    }

    public function test_registra_l_uscita(): void
    {
        $u = $this->redattore();
        Event::dispatch(new \Illuminate\Auth\Events\Logout('web', $u));

        $this->assertSame(Accesso::USCITA, Accesso::latest('id')->first()->esito);
    }

    /**
     * Un accesso aperto impersonando dal control plane non e' un accesso del
     * redattore: senza la distinzione, una modifica dell'assistenza
     * sembrerebbe fatta dal cliente.
     */
    public function test_un_accesso_impersonato_e_segnato_come_tale(): void
    {
        $u = $this->redattore();

        session()->start();
        session()->put(\App\Http\Controllers\ImpersonazioneController::CHIAVE, 1);
        Event::dispatch(new Login('web', $u, false));

        $this->assertTrue(Accesso::latest('id')->first()->impersonato);
    }

    /**
     * Il percorso vero, non l'evento emesso a mano.
     *
     * Gli altri test provano che il listener ragiona bene; questo prova che
     * sia **agganciato**. Senza, bastava dimenticare `Event::listen` nel
     * provider e tutta la suite restava verde con un registro sempre vuoto.
     */
    public function test_un_tentativo_vero_dal_meccanismo_di_login_viene_registrato(): void
    {
        $this->redattore();

        $this->assertTrue(auth('web')->attempt(['email' => 'giulia@c.test', 'password' => 'segretissima']));
        $this->assertFalse(auth('web')->attempt(['email' => 'giulia@c.test', 'password' => 'sbagliata']));

        $esiti = Accesso::orderBy('id')->pluck('esito')->all();

        $this->assertSame([Accesso::RIUSCITO, Accesso::FALLITO], $esiti);
        $this->assertSame('giulia@c.test', Accesso::latest('id')->first()->email);
    }

    // ------------------------------------------------------------- l'avviso

    private function falliti(int $quanti, string $ip = '203.0.113.9', string $email = 'prova@example.com'): void
    {
        foreach (range(1, $quanti) as $_) {
            Accesso::create([
                'guardia' => 'web', 'email' => $email, 'esito' => Accesso::FALLITO, 'ip' => $ip,
            ]);
        }
    }

    public function test_avvisa_quando_qualcuno_insiste(): void
    {
        $this->falliti(9);

        $this->artisan('slimcms:controlla-accessi')->assertSuccessful();

        Mail::assertSentCount(2); // uno per l'indirizzo, uno per l'email
    }

    /** Sotto soglia sono persone che sbagliano la password, non un attacco. */
    public function test_pochi_tentativi_non_fanno_scattare_niente(): void
    {
        $this->falliti(3);

        $this->artisan('slimcms:controlla-accessi')->assertSuccessful();

        Mail::assertNothingSent();
    }

    /**
     * Un avviso che arriva ogni cinque minuti si impara a ignorare, ed e'
     * peggio di nessun avviso.
     */
    public function test_lo_stesso_sospetto_non_avvisa_due_volte(): void
    {
        $this->falliti(9);

        $this->artisan('slimcms:controlla-accessi')->assertSuccessful();
        $this->artisan('slimcms:controlla-accessi')->assertSuccessful();

        Mail::assertSentCount(2);
    }

    /** I tentativi vecchi non contano: la domanda e' su una finestra breve. */
    public function test_i_tentativi_vecchi_non_fanno_scattare_niente(): void
    {
        $this->falliti(9);
        Accesso::query()->update(['created_at' => now()->subDay()]);

        $this->artisan('slimcms:controlla-accessi')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_le_righe_vecchie_vengono_potate(): void
    {
        $this->falliti(2);
        Accesso::query()->update(['created_at' => now()->subDays(200)]);
        $this->falliti(1);

        $this->artisan('slimcms:controlla-accessi')->assertSuccessful();

        $this->assertSame(1, Accesso::count());
    }
}
