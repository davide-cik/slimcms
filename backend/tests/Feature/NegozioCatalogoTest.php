<?php

namespace Tests\Feature;

use App\Enums\Ruolo;
use App\Models\BuildRequest;
use App\Models\Plan;
use App\Models\Prodotto;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Il catalogo del negozio: passo 1 di SlimShop.
 *
 * Un prodotto e' un contenuto come una pagina: scoped per sito, pubblicato
 * solo da un redattore, rigenera il sito quando cambia.
 */
class NegozioCatalogoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Site $sito;

    protected function setUp(): void
    {
        parent::setUp();

        $piano = Plan::create(['name' => 'T', 'price_monthly' => 0, 'max_sites' => 5, 'max_storage_gb' => 1]);
        $this->tenant = Tenant::create(['id' => 'c', 'name' => 'C', 'slug' => 'c', 'status' => 'active', 'plan_id' => $piano->id]);
        $this->sito = $this->nuovoSito('c.test');

        Filament::setCurrentPanel('admin');
        Filament::setTenant($this->sito, isQuiet: true);
        $this->sito->useAsCurrent();
    }

    private function nuovoSito(string $dominio): Site
    {
        return Site::withoutTenancy()->create([
            'tenant_id' => $this->tenant->id, 'domain' => $dominio, 'name' => $dominio, 'shop_attivo' => true,
        ]);
    }

    private function entraCome(Ruolo $ruolo): User
    {
        $utente = User::withoutSitePivotScope()->create([
            'name' => ucfirst($ruolo->value),
            'email' => $ruolo->value . '-' . uniqid() . '@c.test',
            'password' => bcrypt('x'),
        ]);
        $utente->sites()->attach($this->sito, ['role' => $ruolo->value]);
        $this->actingAs($utente);

        return $utente;
    }

    private function prodotto(array $extra = []): Prodotto
    {
        return Prodotto::create(array_merge([
            'nome' => 'Mazzo',
            'slug' => 'mazzo',
            'descrizione' => 'Un mazzo di carte.',
            'prezzo' => 3900,
            'scorte' => 10,
            'status' => 'published',
            'blocks' => [],
        ], $extra));
    }

    public function test_un_prodotto_appartiene_al_sito_corrente(): void
    {
        $p = $this->prodotto();

        $this->assertSame($this->sito->id, $p->site_id);

        $altro = $this->nuovoSito('altro.test');
        $altro->useAsCurrent();

        $this->assertNull(Prodotto::find($p->id), 'Il prodotto di un sito e\' visibile da un altro.');
    }

    public function test_disponibile_dipende_dalle_scorte(): void
    {
        $this->assertTrue($this->prodotto()->disponibile());
        $this->assertFalse($this->prodotto(['slug' => 'finito', 'scorte' => 0])->disponibile());
    }

    public function test_pubblicati_esclude_le_bozze(): void
    {
        $this->prodotto();
        $this->prodotto(['slug' => 'bozza', 'status' => 'draft']);

        $this->assertSame(['mazzo'], Prodotto::query()->pubblicati()->pluck('slug')->all());
    }

    public function test_un_autore_non_pubblica_un_prodotto(): void
    {
        $this->entraCome(Ruolo::Author);

        $this->expectException(AuthorizationException::class);

        $this->prodotto();
    }

    public function test_un_prodotto_pubblicato_accoda_una_build(): void
    {
        // Il sito creato in setUp ha gia' accodato una build `full`
        // (site.created), che assorbe le incremental: senza svuotare la coda il
        // percorso del prodotto non comparirebbe mai in `paths`.
        BuildRequest::query()->where('site_id', $this->sito->id)->delete();

        $this->prodotto();

        $richiesta = BuildRequest::query()->where('site_id', $this->sito->id)->latest('id')->first();

        $this->assertNotNull($richiesta, 'Pubblicare un prodotto non ha accodato nessuna build.');
        $this->assertContains('/prodotti/mazzo', $richiesta->paths ?? []);
    }

    public function test_una_bozza_non_accoda_niente(): void
    {
        // BuildRequest non e' scoped: senza il where si svuoterebbe la coda
        // di tutti i siti.
        BuildRequest::query()->where('site_id', $this->sito->id)->delete();

        $this->prodotto(['status' => 'draft']);

        $this->assertSame(0, BuildRequest::query()->where('site_id', $this->sito->id)->count());
    }
}
