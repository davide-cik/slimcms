<?php

namespace Tests\Feature;

use App\ControlPlane\Models\AdminUser;
use App\Enums\Ruolo;
use App\Filament\Pages\Tenancy\ImpostazioniSito;
use App\Models\Plan;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Services\GeneratoreFavicon;
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

    /**
     * Un SVG caricato non arriva mai al rasterizzatore.
     *
     * E' la correzione di una falla vera, trovata dalla revisione di
     * sicurezza sul commit che ha introdotto questo percorso e riprodotta su
     * questa macchina: un SVG puo' portare
     * `<image xlink:href="text:/percorso/di/un/file">`, e il renderer interno
     * di ImageMagick quel riferimento lo segue e **disegna il contenuto del
     * file dentro l'immagine**. La favicon finisce pubblicata sul sito, cioe'
     * leggibile da chiunque. Nella variante con `file://` usciva il PNG dei
     * media di un altro cliente; con `text:` usciva il contenuto di un file
     * di testo qualsiasi leggibile dal processo.
     *
     * Il riconoscimento e' sui byte, non sull'estensione ne' sul mime: quelli
     * li sceglie chi carica.
     */
    public function test_un_svg_caricato_non_arriva_mai_al_rasterizzatore(): void
    {
        Storage::fake('local');

        $ostile = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" '
            . 'width="600" height="200"><image xlink:href="text:/etc/hostname" width="600" height="200"/></svg>';

        // Anche travestito da PNG nel nome: conta cosa c'e' dentro.
        foreach (['favicon/ostile.svg', 'favicon/travestita.png'] as $percorso) {
            Storage::disk('local')->put($percorso, $ostile);
            $this->site->update(['favicon_path' => $percorso]);

            $this->assertNull(
                app(GeneratoreFavicon::class)->fileCaricato($this->site->fresh()),
                "Un SVG e' arrivato al rasterizzatore da {$percorso}."
            );
        }

        Sanctum::actingAs($this->utente(Ruolo::Admin), ['site:' . $this->site->id]);

        // E l'ICO si produce lo stesso, da quella generata.
        $r = $this->get("/api/sites/{$this->site->domain}/favicon.ico");
        $r->assertOk();
        $this->assertTrue($this->eUnIco($r->getContent()));
    }

    /**
     * E l'SVG del cliente non viene nemmeno ripubblicato.
     *
     * Verrebbe servito dall'origine del suo sito, dove un `<script>` dentro
     * l'SVG e' codice che gira in quell'origine. Con un file caricato il
     * sito dichiara solo l'ICO.
     */
    public function test_l_svg_del_cliente_non_viene_ripubblicato(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('favicon/mia.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $this->site->update(['favicon_path' => 'favicon/mia.svg']);

        Sanctum::actingAs($this->utente(Ruolo::Admin), ['site:' . $this->site->id]);

        $this->get("/api/sites/{$this->site->domain}")
            ->assertOk()
            ->assertJsonPath('data.favicon_svg', null);
    }

    /** Un percorso storto non e' un 500: si torna alla favicon generata. */
    public function test_un_percorso_storto_non_rompe_niente(): void
    {
        $this->site->update(['favicon_path' => '../../../../etc/passwd']);

        Sanctum::actingAs($this->utente(Ruolo::Admin), ['site:' . $this->site->id]);

        $r = $this->get("/api/sites/{$this->site->domain}/favicon.ico");

        $r->assertOk();
        $this->assertTrue($this->eUnIco($r->getContent()));
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
