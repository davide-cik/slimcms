<?php

namespace Tests\Feature;

use App\Enums\Ruolo;
use App\Filament\Resources\Posts\Pages\EditPost;
use App\Filament\Resources\Prodotti\Pages\CreateProdotto;
use App\Filament\Resources\Prodotti\Pages\EditProdotto;
use App\Filament\Resources\Prodotti\Pages\ListProdotti;
use App\Filament\Resources\Prodotti\ProdottoResource;
use App\Models\BuildRequest;
use App\Models\Plan;
use App\Models\Post;
use App\Models\Prodotto;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
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

    public function test_un_autore_crea_un_prodotto_in_bozza(): void
    {
        $this->entraCome(Ruolo::Author);

        Livewire::test(CreateProdotto::class)
            ->fillForm([
                'nome' => 'Kit da tre',
                'slug' => 'kit-da-tre',
                'prezzo' => '99,00',
                'prezzo_barrato' => '117',
                'scorte' => 5,
                'status' => 'draft',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $p = Prodotto::query()->where('slug', 'kit-da-tre')->sole();

        $this->assertSame(9900, $p->prezzo);
        $this->assertSame(11700, $p->prezzo_barrato);
        $this->assertSame('draft', $p->status);
    }

    public function test_il_prezzo_barrato_deve_superare_il_prezzo(): void
    {
        $this->entraCome(Ruolo::Editor);

        Livewire::test(CreateProdotto::class)
            ->fillForm(['nome' => 'X', 'slug' => 'x', 'prezzo' => '39', 'prezzo_barrato' => '30', 'scorte' => 1])
            ->call('create')
            ->assertHasFormErrors(['prezzo_barrato']);
    }

    public function test_un_importo_illeggibile_e_un_errore_nel_campo(): void
    {
        $this->entraCome(Ruolo::Editor);

        Livewire::test(CreateProdotto::class)
            ->fillForm(['nome' => 'X', 'slug' => 'x', 'prezzo' => 'trenta', 'scorte' => 1])
            ->call('create')
            ->assertHasFormErrors(['prezzo']);
    }

    public function test_il_form_mostra_il_prezzo_in_euro(): void
    {
        $this->entraCome(Ruolo::Editor);
        $p = $this->prodotto();

        Livewire::test(EditProdotto::class, ['record' => $p->getRouteKey()])
            ->assertSchemaStateSet(['prezzo' => '39,00']);
    }

    public function test_a_negozio_spento_i_prodotti_non_si_aprono(): void
    {
        $admin = $this->entraCome(Ruolo::Admin);
        // Un admin su un sito deve avere l'MFA attiva (CLAUDE.md): senza,
        // la richiesta vera viene intercettata prima da
        // RichiediMfaSoloAgliAdmin, che rimanda alla pagina di
        // configurazione. Qui si vuole verificare il 403 del negozio
        // spento, non quel middleware, quindi l'MFA si attiva a mano.
        $admin->saveAppAuthenticationSecret('SECRETSECRETSECRETSECR');

        $this->sito->forceFill(['shop_attivo' => false])->save();

        $this->assertFalse(ProdottoResource::canAccess());
        $this->get(ProdottoResource::getUrl('index', tenant: $this->sito))->assertForbidden();

        // Controllo positivo: un 403 qualunque non basta a dimostrare che sia
        // il negozio spento la causa. Riacceso, la stessa URL deve aprirsi.
        // La richiesta appena fatta ha risolto il tenant dalla URL e
        // sostituito quello nel container con un'istanza congelata allo
        // stato di allora: va riallineato, come in setUp, prima di chiedere
        // di nuovo a Filament::getTenant().
        $this->sito->forceFill(['shop_attivo' => true])->save();
        Filament::setTenant($this->sito, isQuiet: true);

        $this->assertTrue(ProdottoResource::canAccess());
        $this->get(ProdottoResource::getUrl('index', tenant: $this->sito))->assertOk();
    }

    /**
     * L'elenco e' la prima pagina che si apre, e le sue colonne hanno
     * chiusure tipizzate (`fn (int $state)`, `fn (string $state)`): un
     * prodotto con prezzo_barrato o descrizione a NULL non le farebbe
     * fallire (sono facoltativi e non compaiono in tabella), ma se una
     * colonna futura leggesse un campo nullable senza controllarlo qui e'
     * dove si scoprirebbe.
     */
    public function test_l_elenco_dei_prodotti_si_apre(): void
    {
        $this->entraCome(Ruolo::Editor);
        $this->prodotto();

        Livewire::test(ListProdotti::class)->assertOk();
    }

    /**
     * Il builder e' condiviso da pagine, articoli e prodotti. I suoi blocchi
     * con immagini dichiaravano `?Page $record`: su un altro modello Filament
     * passa comunque il record per nome, e PHP alza un TypeError.
     */
    public function test_una_galleria_si_apre_anche_fuori_dalle_pagine(): void
    {
        $this->entraCome(Ruolo::Editor);
        $galleria = [['type' => 'galleria', 'data' => ['titolo' => 'Foto', 'media' => []]]];

        $p = $this->prodotto(['blocks' => $galleria]);
        Livewire::test(EditProdotto::class, ['record' => $p->getRouteKey()])->assertOk();

        $post = Post::create(['title' => 'A', 'slug' => 'a', 'status' => 'draft', 'blocks' => $galleria]);
        Livewire::test(EditPost::class, ['record' => $post->getRouteKey()])->assertOk();
    }
}
