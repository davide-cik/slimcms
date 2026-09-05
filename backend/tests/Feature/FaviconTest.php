<?php

namespace Tests\Feature;

use App\ControlPlane\Models\AdminUser;
use App\Enums\Ruolo;
use App\Filament\Pages\Tenancy\ImpostazioniSito;
use App\Models\Plan;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * La favicon del sito: il file ICO e chi puo' cambiarla.
 *
 * `/favicon.ico` non compare in nessun href: lo chiede il browser da solo
 * alla radice del dominio. E' il motivo per cui mancava senza che nessuno se
 * ne accorgesse — fino a quando il monitoraggio dei 404 non ha registrato le
 * richieste.
 */
class FaviconTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $piano = Plan::create(['name' => 'T', 'price_monthly' => 0, 'max_sites' => 5, 'max_storage_gb' => 1]);
        $tenant = Tenant::create(['id' => 'c', 'name' => 'C', 'slug' => 'c', 'status' => 'active', 'plan_id' => $piano->id]);

        $this->site = Site::withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'domain' => 'c.test',
            'name' => 'Studio Rossi',
        ]);
    }

    private function utente(Ruolo $ruolo): User
    {
        $u = User::withoutSitePivotScope()->create([
            'name' => ucfirst($ruolo->value),
            'email' => $ruolo->value . '@c.test',
            'password' => bcrypt('x'),
        ]);
        $u->sites()->attach($this->site, ['role' => $ruolo->value]);

        return $u;
    }

    /** I primi quattro byte di un ICO sono 00 00 01 00. */
    private function eUnIco(string $byte): bool
    {
        return str_starts_with($byte, "\x00\x00\x01\x00");
    }

    public function test_l_api_restituisce_un_ico_vero(): void
    {
        Sanctum::actingAs($this->utente(Ruolo::Admin), ['site:' . $this->site->id]);

        $r = $this->get("/api/sites/{$this->site->domain}/favicon.ico");

        $r->assertOk()->assertHeader('Content-Type', 'image/x-icon');
        $this->assertTrue($this->eUnIco($r->getContent()), 'Non e\' un file ICO.');
    }

    public function test_l_ico_contiene_tre_dimensioni(): void
    {
        Sanctum::actingAs($this->utente(Ruolo::Admin), ['site:' . $this->site->id]);

        $ico = $this->get("/api/sites/{$this->site->domain}/favicon.ico")->getContent();

        // Byte 4-5: il numero di immagini nel file. Tre (16, 32, 48) perche'
        // e' quello che il formato sa fare e che evita al browser di
        // riscalare.
        $this->assertSame(3, unpack('v', substr($ico, 4, 2))[1]);
    }

    /**
     * Il percorso del file caricato non deve uscire dall'API.
     *
     * E' un percorso su un disco privato del backend, non un indirizzo: usato
     * com'era, come href nell'HTML del sito statico, dava un 404 sicuro. E'
     * la ragione per cui "Carica un file" non ha mai funzionato davvero.
     */
    public function test_il_percorso_privato_non_esce_dall_api(): void
    {
        Sanctum::actingAs($this->utente(Ruolo::Admin), ['site:' . $this->site->id]);

        $this->get("/api/sites/{$this->site->domain}")
            ->assertOk()
            ->assertJsonMissingPath('data.favicon_path');
    }

    public function test_un_png_caricato_diventa_l_ico_e_l_svg_sparisce(): void
    {
        Storage::fake('local');

        // Un PNG rosso 64x64, scritto dove lo mette il campo di upload.
        $im = new \Imagick();
        $im->newImage(64, 64, new \ImagickPixel('#cc0000'));
        $im->setImageFormat('png');
        Storage::disk('local')->put('favicon/caricata.png', $im->getImageBlob());

        $this->site->update(['favicon_path' => 'favicon/caricata.png']);

        Sanctum::actingAs($this->utente(Ruolo::Admin), ['site:' . $this->site->id]);

        $ico = $this->get("/api/sites/{$this->site->domain}/favicon.ico")->getContent();
        $this->assertTrue($this->eUnIco($ico));

        // Un PNG non diventa un SVG: il sito in quel caso pubblica solo
        // l'ICO, invece di due icone che si contraddicono.
        $this->get("/api/sites/{$this->site->domain}")
            ->assertOk()
            ->assertJsonPath('data.favicon_svg', null);
    }

    public function test_un_svg_caricato_resta_l_svg_del_sito(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('favicon/mia.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10" fill="#123456"/></svg>');

        $this->site->update(['favicon_path' => 'favicon/mia.svg']);

        Sanctum::actingAs($this->utente(Ruolo::Admin), ['site:' . $this->site->id]);

        $this->get("/api/sites/{$this->site->domain}")
            ->assertOk()
            ->assertJsonPath('data.favicon_svg', fn (?string $svg) => str_contains((string) $svg, '#123456'));
    }

    /**
     * Un percorso rimasto nel database dopo che il file e' sparito non e' un
     * motivo per rispondere 500: si torna a quella generata, che c'e' sempre.
     */
    public function test_un_file_sparito_non_rompe_la_favicon(): void
    {
        Storage::fake('local');
        $this->site->update(['favicon_path' => 'favicon/mai-esistita.png']);

        Sanctum::actingAs($this->utente(Ruolo::Admin), ['site:' . $this->site->id]);

        $r = $this->get("/api/sites/{$this->site->domain}/favicon.ico");

        $r->assertOk();
        $this->assertTrue($this->eUnIco($r->getContent()));
    }

    // --------------------------------------------------- chi puo' cambiarla

    public function test_le_impostazioni_del_sito_sono_dell_amministratore(): void
    {
        Filament::setCurrentPanel('admin');
        Filament::setTenant($this->site, isQuiet: true);
        $this->site->useAsCurrent();

        $this->actingAs($this->utente(Ruolo::Admin));
        Livewire::test(ImpostazioniSito::class)->assertOk();
    }

    public function test_un_redattore_non_apre_le_impostazioni_del_sito(): void
    {
        Filament::setCurrentPanel('admin');
        Filament::setTenant($this->site, isQuiet: true);
        $this->site->useAsCurrent();

        $this->actingAs($this->utente(Ruolo::Editor));
        Livewire::test(ImpostazioniSito::class)->assertNotFound();
    }

    /**
     * `SitePolicy` non deve chiudere fuori il control plane.
     *
     * Aggiungere una policy a un modello cambia il comportamento di TUTTI i
     * pannelli che lo usano: prima non ce n'era e Filament consentiva. Senza
     * l'eccezione per `AdminUser`, questo file avrebbe tolto ai super-admin
     * la loro stessa lista dei siti.
     */
    public function test_il_control_plane_vede_ancora_i_siti(): void
    {
        $admin = AdminUser::create([
            'name' => 'Super',
            'email' => 'super@slimcms.it',
            'password' => bcrypt('x'),
            'role' => 'super-admin',
        ]);

        $this->assertTrue($admin->can('viewAny', Site::class));
        $this->assertTrue($admin->can('update', $this->site));
    }
}
