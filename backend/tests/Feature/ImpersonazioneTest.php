<?php

namespace Tests\Feature;

use App\ControlPlane\Models\AdminUser;
use App\Http\Controllers\ImpersonazioneController;
use App\Models\Impersonazione;
use App\Models\Plan;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * L'impersonazione e' il solo modo in cui un amministratore di piattaforma
 * entra nel pannello dei contenuti. Le garanzie che devono reggere sono tre:
 * il token vale una volta sola, scade, e resta traccia di chi c'era dietro.
 */
class ImpersonazioneTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;
    private User $redattore;
    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $piano = Plan::create(['name' => 'T', 'price_monthly' => 0, 'max_sites' => 5, 'max_storage_gb' => 1]);
        $tenant = Tenant::create(['id' => 'c', 'name' => 'C', 'slug' => 'c', 'status' => 'active', 'plan_id' => $piano->id]);

        $this->site = Site::withoutTenancy()->create(['tenant_id' => $tenant->id, 'domain' => 'c.test', 'name' => 'C']);
        $this->admin = AdminUser::create(['name' => 'Admin', 'email' => 'a@a.it', 'password' => bcrypt('x'), 'role' => 'super-admin']);
        $this->redattore = User::withoutSitePivotScope()->create(['name' => 'Red', 'email' => 'r@r.it', 'password' => bcrypt('x')]);
        $this->redattore->sites()->attach($this->site, ['role' => 'editor']);
    }

    public function test_un_token_valido_autentica_come_il_redattore(): void
    {
        $imp = Impersonazione::apri($this->admin, $this->redattore, $this->site);

        $this->get(route('impersona.entra', $imp->token))
            ->assertRedirect('/admin/' . $this->site->domain);

        $this->assertAuthenticatedAs($this->redattore, 'web');
        $this->assertSame($imp->id, session(ImpersonazioneController::CHIAVE));
        $this->assertNotNull($imp->fresh()->usato_il, 'Il token doveva risultare speso.');
    }

    /** Un token riusabile sarebbe una porta di servizio permanente. */
    public function test_il_token_vale_una_volta_sola(): void
    {
        $imp = Impersonazione::apri($this->admin, $this->redattore, $this->site);

        $this->get(route('impersona.entra', $imp->token))->assertRedirect();
        $this->post(route('impersona.esci'));

        $this->get(route('impersona.entra', $imp->token))->assertForbidden();
    }

    public function test_il_token_scade(): void
    {
        $imp = Impersonazione::apri($this->admin, $this->redattore, $this->site);
        $imp->forceFill(['created_at' => now()->subSeconds(Impersonazione::VALIDITA + 5)])->save();

        $this->get(route('impersona.entra', $imp->token))->assertForbidden();
        $this->assertGuest('web');
    }

    public function test_un_token_inventato_non_apre_niente(): void
    {
        $this->get(route('impersona.entra', 'inventato'))->assertForbidden();
        $this->assertGuest('web');
    }

    public function test_uscire_chiude_la_sessione_e_registra_la_fine(): void
    {
        $imp = Impersonazione::apri($this->admin, $this->redattore, $this->site);
        $this->get(route('impersona.entra', $imp->token));

        $this->post(route('impersona.esci'))->assertRedirect();

        $this->assertGuest('web');
        $this->assertNull(session(ImpersonazioneController::CHIAVE));
        $this->assertNotNull($imp->fresh()->terminata_il, 'La fine dell\'accesso doveva restare registrata.');
    }

    /** L'attribuzione e' il motivo per cui esiste l'impersonazione invece di
     *  dare l'accesso diretto al super-admin. */
    public function test_resta_traccia_di_chi_ce_dietro(): void
    {
        $imp = Impersonazione::apri($this->admin, $this->redattore, $this->site);
        $this->get(route('impersona.entra', $imp->token));

        $registrata = Impersonazione::with(['adminUser', 'user', 'site'])->find($imp->id);

        $this->assertSame($this->admin->id, $registrata->adminUser->id);
        $this->assertSame($this->redattore->id, $registrata->user->id);
        $this->assertSame($this->site->id, $registrata->site->id);
    }
}
