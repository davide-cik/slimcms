<?php

namespace Tests\Feature;

use App\ControlPlane\Filament\Resources\Sites\Pages\CreateSite;
use App\ControlPlane\Filament\Resources\Sites\Pages\EditSite;
use App\ControlPlane\Models\AdminUser;
use App\Models\Plan;
use App\Models\Site;
use App\Models\Tenant;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Cosa resta nel control plane: il ciclo di vita del sito.
 *
 * A chi appartiene, che indirizzo ha, come sta il dominio. Come si presenta —
 * testata, footer, blog, favicon, moduli — lo decide chi il sito lo abita,
 * da «Impostazioni del sito». Questo test fissa il confine nei due sensi:
 * quello che deve restare qui, e quello che non deve piu' esserci.
 */
class CicloDiVitaSitoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $piano = Plan::create(['name' => 'T', 'price_monthly' => 0, 'max_sites' => 5, 'max_storage_gb' => 1]);
        $this->tenant = Tenant::create([
            'id' => 'c', 'name' => 'C', 'slug' => 'c', 'status' => 'active', 'plan_id' => $piano->id,
        ]);

        $admin = AdminUser::create([
            'name' => 'A', 'email' => 'a@a.it', 'password' => bcrypt('x'), 'role' => 'super-admin',
        ]);

        $this->actingAs($admin, 'manage');
        Filament::setCurrentPanel('manage');
    }

    /**
     * Il nome del sito e' `visibleOn('create')`: dopo si cambia dal pannello
     * del sito. Se sparisse anche dalla creazione, un sito nascerebbe senza
     * nome — e la colonna non lo consente.
     */
    public function test_un_sito_si_crea_dal_control_plane(): void
    {
        Livewire::test(CreateSite::class)
            ->fillForm([
                'tenant_id' => $this->tenant->id,
                'domain' => 'nuovo.test',
                'name' => 'Sito nuovo',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $sito = Site::withoutTenancy()->where('domain', 'nuovo.test')->sole();

        $this->assertSame('Sito nuovo', $sito->name);
        $this->assertSame($this->tenant->id, $sito->tenant_id);
    }

    /**
     * Il dominio si pulisce **prima** della validazione.
     *
     * La regola `regex` gira sullo stato del campo: normalizzando solo al
     * salvataggio, uno spazio di troppo o una maiuscola diventavano "formato
     * non valido" invece di essere tolti. E il `www.` va tolto davvero, non
     * solo chiesto nel testo d'aiuto: `RisolviSitoDaParametro` lo toglie
     * dall'indirizzo in arrivo e confronta con questa colonna, quindi un sito
     * salvato come `www.cliente.it` non lo troverebbe nessuna richiesta.
     */
    public function test_il_dominio_si_normalizza_prima_di_essere_validato(): void
    {
        Livewire::test(CreateSite::class)
            ->fillForm(['tenant_id' => $this->tenant->id, 'name' => 'N'])
            ->fillForm(['domain' => '  https://WWW.Nuovo.IT/ '])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertTrue(
            Site::withoutTenancy()->where('domain', 'nuovo.it')->exists(),
            'Il dominio non e\' stato normalizzato: '
                . Site::withoutTenancy()->pluck('domain')->implode(', ')
        );
    }

    /**
     * La configurazione del sito NON e' piu' qui.
     *
     * Il confine va fissato anche al negativo: se un giorno una sezione
     * tornasse nel control plane, il cliente ricomincerebbe a chiedere a noi
     * di cambiarsi la testata senza che nessun test se ne accorga.
     */
    public function test_la_configurazione_del_sito_non_e_piu_nel_control_plane(): void
    {
        $sito = Site::withoutTenancy()->create([
            'tenant_id' => $this->tenant->id, 'domain' => 'c.test', 'name' => 'C',
        ]);

        $componenti = Livewire::test(EditSite::class, ['record' => $sito->getRouteKey()])
            ->instance()->form->getFlatComponents(withHidden: true);

        $nomi = collect($componenti)
            ->map(fn ($c) => method_exists($c, 'getName') ? $c->getName() : null)
            ->filter()
            ->all();

        foreach (['layout_config', 'footer_config', 'og_config', 'seo_defaults', 'favicon_path', 'contact_email'] as $fuoriposto) {
            $trovati = array_values(array_filter($nomi, fn ($n) => str_starts_with((string) $n, $fuoriposto)));

            $this->assertSame([], $trovati, "«{$fuoriposto}» e' tornato nel control plane: "
                . 'la configurazione del sito si cambia dal pannello del sito.');
        }
    }

    /** Quello che invece deve restare: a chi appartiene e che indirizzo ha. */
    public function test_il_control_plane_tiene_cliente_dominio_e_stato(): void
    {
        $sito = Site::withoutTenancy()->create([
            'tenant_id' => $this->tenant->id, 'domain' => 'c.test', 'name' => 'C',
        ]);

        $nomi = collect(Livewire::test(EditSite::class, ['record' => $sito->getRouteKey()])
            ->instance()->form->getFlatComponents(withHidden: true))
            ->map(fn ($c) => method_exists($c, 'getName') ? $c->getName() : null)
            ->filter()
            ->all();

        foreach (['tenant_id', 'domain'] as $atteso) {
            $this->assertContains($atteso, $nomi, "«{$atteso}» deve restare nel control plane.");
        }
    }
}
