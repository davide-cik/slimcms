<?php

namespace Tests\Feature;

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
 * Tutto il sito sta nel CMS: testata, footer, verifiche webmaster e
 * analytics si configurano dal pannello, non si cablano nel layout Astro.
 *
 * Il test monta il form davvero invece di ispezionarne lo schema: e' l'unico
 * modo di accorgersi che un campo annidato (layout_config.voci.*) non viene
 * idratato o non viene salvato. Uno schema puo' essere giusto e il giro
 * completo rotto lo stesso.
 */
class ConfigurazioneSitoTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $piano = Plan::create(['name' => 'T', 'price_monthly' => 0, 'max_sites' => 5, 'max_storage_gb' => 1]);
        $tenant = Tenant::create(['id' => 'c', 'name' => 'C', 'slug' => 'c', 'status' => 'active', 'plan_id' => $piano->id]);
        $this->site = Site::withoutTenancy()->create(['tenant_id' => $tenant->id, 'domain' => 'c.test', 'name' => 'C']);

        $admin = AdminUser::create(['name' => 'A', 'email' => 'a@a.it', 'password' => bcrypt('x'), 'role' => 'super-admin']);

        $this->actingAs($admin, 'manage');
        Filament::setCurrentPanel('manage');
    }

    private function modifica(array $dati): void
    {
        Livewire::test(EditSite::class, ['record' => $this->site->getRouteKey()])
            ->fillForm($dati)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->site->refresh();
    }

    /**
     * Il pannello e il layout Astro devono conoscere le STESSE disposizioni.
     *
     * E' la giuntura che ha prodotto quasi tutti i guasti del progetto: due
     * elenchi scritti in file diversi, ciascuno verificato contro l'idea
     * dell'altro. Una disposizione offerta e non resa darebbe una testata
     * senza stile; una resa e non offerta sarebbe codice morto che nessuno
     * puo' attivare.
     */
    public function test_le_disposizioni_della_testata_sono_le_stesse_nel_pannello_e_in_astro(): void
    {
        $sorgente = file_get_contents(__DIR__ . '/../../../frontend/src/layouts/Base.astro');

        $this->assertNotFalse($sorgente, 'Base.astro non trovato: il percorso del monorepo e\' cambiato?');

        preg_match("/const tipiTestata = \[(.*?)\]/s", (string) $sorgente, $trovato);
        $this->assertNotEmpty($trovato, 'In Base.astro non c\'e\' piu\' l\'elenco tipiTestata.');

        preg_match_all("/'([a-z]+)'/", $trovato[1], $inAstro);

        $componente = Livewire::test(EditSite::class, ['record' => $this->site->getRouteKey()]);
        $radio = $componente->instance()->form->getComponent(
            fn ($c) => $c instanceof \Filament\Forms\Components\Radio && $c->getName() === 'layout_config.tipo'
        );

        $this->assertNotNull($radio, 'Il campo della disposizione non e\' nel form.');

        $nelPannello = array_keys($radio->getOptions());

        sort($nelPannello);
        $daAstro = $inAstro[1];
        sort($daAstro);

        $this->assertSame($daAstro, $nelPannello, implode(' ', [
            'Le disposizioni del pannello e quelle rese da Astro non coincidono.',
            'Pannello: ' . implode(', ', $nelPannello) . '.',
            'Astro: ' . implode(', ', $daAstro) . '.',
        ]));
    }

    public function test_la_testata_si_configura_dal_pannello(): void
    {
        $this->modifica([
            'layout_config.mostra_logo' => true,
            'layout_config.nome_visibile' => 'Acme',
            'layout_config.voci' => [
                ['etichetta' => 'Servizi', 'url' => '/servizi/', 'evidenza' => false],
                ['etichetta' => 'Scrivici', 'url' => 'mailto:ciao@acme.it', 'evidenza' => true],
            ],
        ]);

        $this->assertSame('Acme', $this->site->layout_config['nome_visibile']);
        $this->assertCount(2, $this->site->layout_config['voci']);
        $this->assertTrue($this->site->layout_config['voci'][1]['evidenza']);
    }

    public function test_il_footer_a_colonne_si_salva_con_i_suoi_collegamenti(): void
    {
        $this->modifica([
            'footer_config.tipo' => 'colonne',
            'footer_config.colonne' => 2,
            'footer_config.blocchi' => [
                ['titolo' => 'Prodotto', 'voci' => [['etichetta' => 'Prezzi', 'url' => '/prezzi/']]],
                ['titolo' => 'Azienda', 'voci' => [['etichetta' => 'Chi siamo', 'url' => '/chi-siamo/']]],
            ],
            'footer_config.legale' => '© 2026 Acme · P.IVA IT00000000000',
        ]);

        $this->assertSame('colonne', $this->site->footer_config['tipo']);
        $this->assertSame(2, (int) $this->site->footer_config['colonne']);
        $this->assertSame('Prezzi', $this->site->footer_config['blocchi'][0]['voci'][0]['etichetta']);
    }

    public function test_il_codice_di_verifica_si_accetta_anche_come_tag_intero(): void
    {
        // E' quello che Google mostra per primo nella console: chi lo incolla
        // cosi' non ha sbagliato, e salvare markup lo renderebbe inservibile.
        $this->modifica([
            'seo_defaults.webmaster.google' => '<meta name="google-site-verification" content="abc123XYZ" />',
            'seo_defaults.webmaster.bing' => 'PLAIN456',
        ]);

        $this->assertSame('abc123XYZ', $this->site->seo_defaults['webmaster']['google']);
        $this->assertSame('PLAIN456', $this->site->seo_defaults['webmaster']['bing']);
    }

    public function test_un_id_analytics_sbagliato_viene_rifiutato(): void
    {
        Livewire::test(EditSite::class, ['record' => $this->site->getRouteKey()])
            ->fillForm(['seo_defaults.analytics.ga4' => 'UA-12345-1'])
            ->call('save')
            ->assertHasFormErrors(['seo_defaults.analytics.ga4']);
    }

    public function test_un_id_analytics_valido_si_salva_maiuscolo(): void
    {
        $this->modifica(['seo_defaults.analytics.ga4' => 'g-ab12cd34ef']);

        $this->assertSame('G-AB12CD34EF', $this->site->seo_defaults['analytics']['ga4']);
    }

    public function test_l_api_espone_la_configurazione_al_frontend(): void
    {
        $this->site->update([
            'layout_config' => ['voci' => [['etichetta' => 'X', 'url' => '/x/']]],
            'seo_defaults' => ['analytics' => ['ga4' => 'G-ABC123']],
        ]);

        $json = (new \App\Http\Resources\SiteResource($this->site))->toArray(request());

        $this->assertSame('X', $json['layout_config']['voci'][0]['etichetta']);
        $this->assertSame('G-ABC123', $json['seo_defaults']['analytics']['ga4']);
    }
}
