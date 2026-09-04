<?php

namespace Tests\Feature;

use App\Filament\Resources\Posts\Pages\EditPost;
use App\Http\Resources\PostResource;
use App\Models\Plan;
use App\Models\Post;
use App\Models\Site;
use App\Models\Tag;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * I tag sono righe scoped per sito, non piu' stringhe in una colonna JSON.
 *
 * La forma vecchia non permetteva un archivio /tag/<slug>/, non si rinominava
 * in un colpo solo, teneva "Performance" e "performance" come due cose
 * diverse per sempre, e soprattutto non era una riga: il concetto di tag di
 * un cliente non esisteva, quindi TenantScopeTest non poteva coprirlo.
 */
class TagTest extends TestCase
{
    use RefreshDatabase;

    private Site $sitoA;
    private Site $sitoB;
    private Post $articolo;

    protected function setUp(): void
    {
        parent::setUp();

        $piano = Plan::create(['name' => 'T', 'price_monthly' => 0, 'max_sites' => 5, 'max_storage_gb' => 1]);
        $tenant = Tenant::create(['id' => 'c', 'name' => 'C', 'slug' => 'c', 'status' => 'active', 'plan_id' => $piano->id]);

        $this->sitoA = Site::withoutTenancy()->create(['tenant_id' => $tenant->id, 'domain' => 'a.test', 'name' => 'A']);
        $this->sitoB = Site::withoutTenancy()->create(['tenant_id' => $tenant->id, 'domain' => 'b.test', 'name' => 'B']);

        $redattore = User::withoutSitePivotScope()->create(['name' => 'R', 'email' => 'r@r.it', 'password' => bcrypt('x')]);
        $redattore->sites()->attach($this->sitoA, ['role' => 'editor']);

        $this->actingAs($redattore);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($this->sitoA, isQuiet: true);
        $this->sitoA->useAsCurrent();

        $this->articolo = Post::create([
            'title' => 'Un articolo',
            'slug' => 'un-articolo',
            'status' => 'published',
            'publish_at' => now()->subDay(),
            'blocks' => [],
        ]);
    }

    public function test_due_siti_possono_avere_lo_stesso_tag(): void
    {
        // E' il motivo per cui l'unicita' e' su (site_id, slug) e non solo
        // sullo slug: "performance" e' un tag ovvio, lo useranno in molti.
        $a = Tag::create(['name' => 'Performance', 'slug' => 'performance']);

        $this->sitoB->useAsCurrent();
        $b = Tag::create(['name' => 'Performance', 'slug' => 'performance']);

        $this->assertNotSame($a->id, $b->id);
        $this->assertSame($this->sitoB->id, $b->site_id);
    }

    public function test_un_sito_non_vede_i_tag_di_un_altro(): void
    {
        Tag::create(['name' => 'Solo di A', 'slug' => 'solo-di-a']);

        $this->sitoB->useAsCurrent();

        $this->assertSame(0, Tag::count(), 'Lo scope per sito non filtra i tag.');
    }

    public function test_i_tag_si_assegnano_dal_pannello(): void
    {
        $tag = Tag::create(['name' => 'Migrazione', 'slug' => 'migrazione']);

        Livewire::test(EditPost::class, ['record' => $this->articolo->getRouteKey()])
            ->fillForm(['tags' => [$tag->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['Migrazione'], $this->articolo->fresh()->tags->pluck('name')->all());
    }

    public function test_l_api_espone_nome_e_slug(): void
    {
        $tag = Tag::create(['name' => 'Performance', 'slug' => 'performance']);
        $this->articolo->tags()->attach($tag);

        // whenLoaded: senza l'eager loading nel controller la chiave sparisce
        // in silenzio, ed e' esattamente il modo in cui questo genere di
        // errore non si nota.
        $json = (new PostResource($this->articolo->fresh()->load('tags')))->toArray(request());

        $this->assertSame([['name' => 'Performance', 'slug' => 'performance']], $json['tags']->all());
    }

    public function test_l_api_filtra_gli_articoli_per_tag(): void
    {
        $tag = Tag::create(['name' => 'Performance', 'slug' => 'performance']);
        $this->articolo->tags()->attach($tag);

        Post::create([
            'title' => 'Senza tag', 'slug' => 'senza-tag',
            'status' => 'published', 'publish_at' => now()->subDay(), 'blocks' => [],
        ]);

        $token = $this->tokenDiBuild();

        $risposta = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->getJson("/api/sites/{$this->sitoA->domain}/posts?tag=performance");

        $risposta->assertOk();
        $this->assertSame(['un-articolo'], array_column($risposta->json('data'), 'slug'));
    }

    public function test_il_filtro_usa_lo_slug_non_il_nome(): void
    {
        // Prima il filtro cercava la stringa esatta nella colonna JSON:
        // "Performance" e "performance" erano due filtri diversi.
        $tag = Tag::create(['name' => 'Performance Web', 'slug' => 'performance-web']);
        $this->articolo->tags()->attach($tag);

        $token = $this->tokenDiBuild();
        $intestazioni = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];

        $this->withHeaders($intestazioni)
            ->getJson("/api/sites/{$this->sitoA->domain}/posts?tag=performance-web")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->withHeaders($intestazioni)
            ->getJson("/api/sites/{$this->sitoA->domain}/posts?tag=Performance%20Web")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_uno_slug_duplicato_da_un_messaggio_non_un_errore_sql(): void
    {
        Tag::create(['name' => 'Città', 'slug' => 'citta']);

        // Due nomi diversi possono produrre lo stesso slug. Senza la regola,
        // il vincolo del database scatta a valle e il redattore riceve una
        // pagina di errore SQL invece di un messaggio nel form.
        Livewire::test(\App\Filament\Resources\Tags\Pages\CreateTag::class)
            ->fillForm(['name' => 'Citta', 'slug' => 'citta'])
            ->call('create')
            ->assertHasFormErrors(['slug']);

        $this->assertSame(1, Tag::count());
    }

    public function test_lo_stesso_slug_resta_libero_sugli_altri_siti(): void
    {
        Tag::create(['name' => 'Novita', 'slug' => 'novita']);

        // La regola `unique` di Laravel interroga la TABELLA, non il modello:
        // senza il where esplicito sul sito, un tag "novita" di un cliente
        // impedirebbe a tutti gli altri di averne uno con lo stesso nome.
        $this->sitoB->useAsCurrent();
        Filament::setTenant($this->sitoB, isQuiet: true);

        Livewire::test(\App\Filament\Resources\Tags\Pages\CreateTag::class)
            ->fillForm(['name' => 'Novita', 'slug' => 'novita'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(1, Tag::count());
    }

    private function tokenDiBuild(): string
    {
        return auth()->user()->createToken('test', ["site:{$this->sitoA->id}"])->plainTextToken;
    }
}
