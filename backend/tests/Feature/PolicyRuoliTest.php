<?php

namespace Tests\Feature;

use App\Enums\Ruolo;
use App\Filament\Resources\Pages\Pages\CreatePage;
use App\Filament\Resources\Pages\Pages\EditPage;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\Page;
use App\Models\Plan;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Support\RuoloCorrente;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Cosa puo' fare davvero ognuno dei quattro ruoli.
 *
 * Le verifiche passano dai componenti Livewire veri e non dai metodi della
 * policy: chiamare `$policy->update()` prova che la policy ragiona bene, non
 * che Filament la interroghi. Fino a ieri le policy non c'erano affatto e
 * ogni metodo qui sotto sarebbe fallito — il pannello lasciava fare tutto a
 * tutti, comprese le promozioni a amministratore.
 */
class PolicyRuoliTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $piano = Plan::create(['name' => 'Test', 'price_monthly' => 0, 'max_sites' => 5, 'max_storage_gb' => 1]);
        $tenant = Tenant::create(['id' => 'cliente', 'name' => 'Cliente', 'slug' => 'cliente', 'status' => 'active', 'plan_id' => $piano->id]);

        $this->site = Site::withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'domain' => 'cliente.test',
            'name' => 'Cliente',
        ]);

        Filament::setCurrentPanel('admin');
        Filament::setTenant($this->site, isQuiet: true);
        $this->site->useAsCurrent();

        Page::create(['title' => 'Home', 'slug' => 'home', 'is_home' => true]);
    }

    /** Entra nel pannello con un ruolo preciso. */
    private function entraCome(Ruolo $ruolo, ?Site $sito = null): User
    {
        $utente = User::withoutSitePivotScope()->create([
            'name' => ucfirst($ruolo->value),
            'email' => $ruolo->value . '-' . uniqid() . '@cliente.test',
            'password' => bcrypt('password'),
        ]);

        $utente->sites()->attach($sito ?? $this->site, ['role' => $ruolo->value]);

        $this->actingAs($utente);

        return $utente;
    }

    // ---------------------------------------------------------------- contratto

    /**
     * Ogni risorsa del pannello dei contenuti ha una policy.
     *
     * Senza policy Filament consente tutto: e' proprio il difetto che questo
     * commit chiude, e una risorsa nuova lo reintrodurrebbe in silenzio.
     */
    public function test_ogni_risorsa_del_pannello_ha_una_policy(): void
    {
        $senza = [];

        foreach (glob(app_path('Filament/Resources/*/*Resource.php')) as $file) {
            $classe = 'App\\Filament\\Resources\\'
                . str_replace('/', '\\', substr($file, strlen(app_path('Filament/Resources/')), -4));

            $modello = $classe::getModel();

            if (Gate::getPolicyFor($modello) === null) {
                $senza[] = class_basename($classe) . ' (' . class_basename($modello) . ')';
            }
        }

        $this->assertSame([], $senza, "Risorse senza policy: chi accede al pannello puo' farci tutto.\n"
            . implode("\n", $senza));
    }

    /**
     * Ogni policy dichiara tutte le abilita' che Filament interroga.
     *
     * Una che manca non nega: consente. `get_authorization_response()` cade
     * fino a `Response::allow()` quando la policy esiste ma il metodo no,
     * quindi una policy incompleta e' peggio di nessuna policy — sembra
     * scritta.
     */
    public function test_ogni_policy_dichiara_tutte_le_abilita_che_filament_interroga(): void
    {
        $abilita = ['viewAny', 'view', 'create', 'update', 'delete', 'deleteAny',
            'forceDelete', 'forceDeleteAny', 'reorder', 'replicate', 'restore', 'restoreAny'];

        $mancanti = [];

        foreach (glob(app_path('Policies/*Policy.php')) as $file) {
            $classe = 'App\\Policies\\' . basename($file, '.php');

            if ((new \ReflectionClass($classe))->isAbstract()) {
                continue;
            }

            foreach ($abilita as $a) {
                if (! method_exists($classe, $a)) {
                    $mancanti[] = class_basename($classe) . '::' . $a . '()';
                }
            }
        }

        $this->assertSame([], $mancanti, "Abilita' mancanti (che Filament tratta come consentite):\n"
            . implode("\n", $mancanti));
    }

    // ---------------------------------------------------- gestione dei redattori

    public function test_un_redattore_non_puo_aprire_la_gestione_dei_redattori(): void
    {
        $this->entraCome(Ruolo::Editor);

        Livewire::test(ListUsers::class)->assertForbidden();
    }

    public function test_un_autore_non_puo_aprire_la_gestione_dei_redattori(): void
    {
        $this->entraCome(Ruolo::Author);

        Livewire::test(ListUsers::class)->assertForbidden();
    }

    /**
     * Lo stesso divieto sulla URL vera, non solo sul componente montato a
     * mano: e' la porta da cui passerebbe qualcuno, e la catena di middleware
     * (autenticazione, tenant, MFA) e' diversa da quella di un test Livewire.
     */
    public function test_la_url_dei_redattori_risponde_403_a_un_redattore(): void
    {
        $this->entraCome(Ruolo::Editor);

        $this->get(UserResource::getUrl('index', tenant: $this->site))
            ->assertForbidden();
    }

    public function test_un_amministratore_apre_la_gestione_dei_redattori(): void
    {
        $this->entraCome(Ruolo::Admin);

        Livewire::test(ListUsers::class)->assertOk();
    }

    public function test_un_amministratore_non_puo_togliersi_da_solo_dal_sito(): void
    {
        $admin = $this->entraCome(Ruolo::Admin);
        $altro = $this->entraCome(Ruolo::Editor);

        $this->actingAs($admin);

        $this->assertFalse($admin->can('delete', $admin), 'Chiudere la porta da dentro.');
        $this->assertTrue($admin->can('delete', $altro));
    }

    public function test_nessuno_concede_un_ruolo_piu_alto_del_proprio(): void
    {
        $this->entraCome(Ruolo::Editor);

        $this->assertSame(Ruolo::Editor, RuoloCorrente::concedibile('admin'));
        $this->assertSame(Ruolo::Author, RuoloCorrente::concedibile('author'));

        $this->entraCome(Ruolo::Admin);

        $this->assertSame(Ruolo::Admin, RuoloCorrente::concedibile('admin'));
    }

    public function test_un_valore_inventato_non_concede_niente_di_piu(): void
    {
        $this->entraCome(Ruolo::Admin);

        $this->assertSame(Ruolo::Author, RuoloCorrente::concedibile('superadmin'));
    }

    // ------------------------------------------------------------- i contenuti

    public function test_un_lettore_non_puo_modificare_una_pagina(): void
    {
        $this->entraCome(Ruolo::Editor);
        $pagina = Page::create(['title' => 'Chi siamo', 'slug' => 'chi-siamo']);

        $this->entraCome(Ruolo::Viewer);

        Livewire::test(EditPage::class, ['record' => $pagina->getRouteKey()])
            ->assertForbidden();
    }

    public function test_un_lettore_non_puo_creare_una_pagina(): void
    {
        $this->entraCome(Ruolo::Viewer);

        Livewire::test(CreatePage::class)->assertForbidden();
    }

    public function test_un_autore_crea_una_bozza(): void
    {
        $this->entraCome(Ruolo::Author);

        Livewire::test(CreatePage::class)
            ->fillForm(['title' => 'Bozza mia', 'slug' => 'bozza-mia', 'status' => 'draft'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('pages', ['slug' => 'bozza-mia', 'status' => 'draft']);
    }

    public function test_un_autore_non_puo_pubblicare_dal_form(): void
    {
        $this->entraCome(Ruolo::Author);

        Livewire::test(CreatePage::class)
            ->fillForm(['title' => 'Vorrei online', 'slug' => 'vorrei-online', 'status' => 'published'])
            ->call('create')
            ->assertHasFormErrors(['status']);

        $this->assertDatabaseMissing('pages', ['slug' => 'vorrei-online']);
    }

    /**
     * La stessa cosa senza passare dal form: e' li' che conta davvero,
     * perche' lo stato di un componente Livewire arriva dal browser e una
     * tendina disabilitata non ferma nessuno.
     */
    public function test_il_modello_rifiuta_la_pubblicazione_di_un_autore(): void
    {
        $this->entraCome(Ruolo::Author);

        $this->expectException(AuthorizationException::class);

        Page::create(['title' => 'Forzata', 'slug' => 'forzata', 'status' => 'published']);
    }

    public function test_anche_programmare_e_pubblicare(): void
    {
        $this->entraCome(Ruolo::Author);

        $this->expectException(AuthorizationException::class);

        Page::create(['title' => 'Piu tardi', 'slug' => 'piu-tardi', 'status' => 'scheduled', 'publish_at' => now()->addDay()]);
    }

    public function test_un_autore_corregge_una_pagina_gia_pubblicata(): void
    {
        $this->entraCome(Ruolo::Editor);
        $pagina = Page::create(['title' => 'Servizi', 'slug' => 'servizi', 'status' => 'published']);

        $this->entraCome(Ruolo::Author);

        $pagina->refresh()->update(['title' => 'Servizi e consulenza']);

        $this->assertSame('Servizi e consulenza', $pagina->fresh()->title);
        $this->assertSame('published', $pagina->fresh()->status);
    }

    public function test_un_redattore_pubblica(): void
    {
        $this->entraCome(Ruolo::Editor);

        Livewire::test(CreatePage::class)
            ->fillForm(['title' => 'Online', 'slug' => 'online', 'status' => 'published'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('pages', ['slug' => 'online', 'status' => 'published']);
    }

    /**
     * Il ruolo vale sul sito su cui e' stato dato, non in generale.
     *
     * Le query del pannello sono gia' scoped, quindi un record di un altro
     * cliente non dovrebbe mai arrivare a una policy: se ci arriva, qualcosa
     * si e' rotto a monte e la risposta giusta e' no.
     */
    public function test_il_ruolo_non_vale_sui_contenuti_di_un_altro_sito(): void
    {
        $altroSito = Site::withoutTenancy()->create([
            'tenant_id' => Tenant::create([
                'id' => 'altro', 'name' => 'Altro', 'slug' => 'altro', 'status' => 'active',
                'plan_id' => Plan::first()->id,
            ])->id,
            'domain' => 'altro.test',
            'name' => 'Altro',
        ]);

        $paginaAltrui = Page::withoutSiteScope()->create([
            'site_id' => $altroSito->id,
            'title' => 'Altrui',
            'slug' => 'altrui',
            'is_home' => true,
        ]);

        $admin = $this->entraCome(Ruolo::Admin);

        $this->assertFalse($admin->can('update', $paginaAltrui));
        $this->assertFalse($admin->can('view', $paginaAltrui));
    }
}
