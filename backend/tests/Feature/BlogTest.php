<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Page;
use App\Models\Plan;
use App\Models\Post;
use App\Models\Site;
use App\Models\Tag;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Il blog dal lato dell'API: quello che il worker di build riceve.
 *
 * Il segmento sotto cui vivono gli articoli e' configurabile per sito, e
 * compare in tre posti che devono concordare — sitemap, JSON-LD e rotte
 * generate da Astro. Laravel lo normalizza una volta sola.
 */
class BlogTest extends TestCase
{
    use RefreshDatabase;

    private Site $sito;
    private User $redattore;

    protected function setUp(): void
    {
        parent::setUp();

        $piano = Plan::create(['name' => 'T', 'price_monthly' => 0, 'max_sites' => 5, 'max_storage_gb' => 1]);
        $tenant = Tenant::create(['id' => 'c', 'name' => 'C', 'slug' => 'c', 'status' => 'active', 'plan_id' => $piano->id]);
        $this->sito = Site::withoutTenancy()->create(['tenant_id' => $tenant->id, 'domain' => 'c.test', 'name' => 'C']);

        $this->redattore = User::withoutSitePivotScope()->create(['name' => 'R', 'email' => 'r@r.it', 'password' => bcrypt('x')]);
        $this->redattore->sites()->attach($this->sito, ['role' => 'editor']);

        $this->sito->useAsCurrent();
    }

    private function sitemap(): array
    {
        Sanctum::actingAs($this->redattore, ["site:{$this->sito->id}"]);

        return $this->getJson("/api/sites/{$this->sito->domain}/sitemap")->json();
    }

    private function articolo(string $slug, array $extra = []): Post
    {
        return Post::create(array_merge([
            'title' => ucfirst($slug),
            'slug' => $slug,
            'status' => 'published',
            'publish_at' => now()->subDay(),
            'blocks' => [],
        ], $extra));
    }

    public function test_la_base_del_blog_ha_un_valore_predefinito(): void
    {
        $this->assertSame('blog', $this->sito->baseBlog());
    }

    public function test_una_base_assurda_ricade_su_quella_predefinita(): void
    {
        // Vuota o con caratteri strani metterebbe gli articoli sulla radice,
        // dove si scontrerebbero con gli slug delle pagine.
        foreach (['', '/', '../etc', 'con spazio', str_repeat('a', 50)] as $valore) {
            $this->sito->forceFill(['layout_config' => ['blog' => ['base' => $valore]]])->saveQuietly();

            $this->assertSame('blog', $this->sito->fresh()->baseBlog(), "Base '{$valore}' non ricaduta sul default.");
        }
    }

    public function test_la_sitemap_elenca_articoli_indice_e_archivi(): void
    {
        $categoria = Category::create(['name' => 'Dietro le quinte', 'slug' => 'dietro-le-quinte']);
        $tag = Tag::create(['name' => 'Performance', 'slug' => 'performance']);

        $articolo = $this->articolo('primo-articolo');
        $articolo->categories()->attach($categoria);
        $articolo->tags()->attach($tag);

        Page::create(['title' => 'Home', 'slug' => 'home', 'is_home' => true, 'status' => 'published', 'blocks' => []]);

        $urls = array_column($this->sitemap()['urls'], 'loc');

        $this->assertContains('https://c.test/', $urls);
        $this->assertContains('https://c.test/blog/', $urls);
        $this->assertContains('https://c.test/blog/primo-articolo/', $urls);
        $this->assertContains('https://c.test/blog/categoria/dietro-le-quinte/', $urls);
        $this->assertContains('https://c.test/blog/tag/performance/', $urls);
    }

    public function test_la_sitemap_segue_la_base_configurata(): void
    {
        // Se divergesse, la sitemap elencherebbe indirizzi che rispondono 404
        // e il gate di deploy fermerebbe la pubblicazione — bene, ma tardi.
        $this->sito->forceFill(['layout_config' => ['blog' => ['base' => 'news']]])->saveQuietly();

        $this->articolo('primo-articolo');

        $urls = array_column($this->sitemap()['urls'], 'loc');

        $this->assertContains('https://c.test/news/primo-articolo/', $urls);
        $this->assertNotContains('https://c.test/blog/primo-articolo/', $urls);
    }

    public function test_senza_articoli_non_c_e_l_indice_del_blog(): void
    {
        Page::create(['title' => 'Home', 'slug' => 'home', 'is_home' => true, 'status' => 'published', 'blocks' => []]);

        $urls = array_column($this->sitemap()['urls'], 'loc');

        // Un indice vuoto e' una pagina sottile: i motori la segnalano.
        $this->assertNotContains('https://c.test/blog/', $urls);
    }

    public function test_un_archivio_senza_articoli_pubblicati_non_finisce_nella_sitemap(): void
    {
        // Il termine resta in tabella anche quando l'ultimo articolo che lo
        // usava torna bozza.
        $tag = Tag::create(['name' => 'Orfano', 'slug' => 'orfano']);
        $bozza = $this->articolo('bozza', ['status' => 'draft', 'publish_at' => null]);
        $bozza->tags()->attach($tag);

        $this->articolo('pubblicato');

        $urls = array_column($this->sitemap()['urls'], 'loc');

        $this->assertContains('https://c.test/blog/pubblicato/', $urls);
        $this->assertNotContains('https://c.test/blog/tag/orfano/', $urls);
    }

    public function test_un_articolo_noindex_resta_fuori_dalla_sitemap(): void
    {
        $this->articolo('nascosto', ['seo' => ['noindex' => true]]);
        $this->articolo('visibile');

        $urls = array_column($this->sitemap()['urls'], 'loc');

        $this->assertContains('https://c.test/blog/visibile/', $urls);
        $this->assertNotContains('https://c.test/blog/nascosto/', $urls);
    }

    public function test_l_immagine_og_distingue_pagina_e_articolo_con_lo_stesso_slug(): void
    {
        // Due tabelle diverse, slug uguali legittimi: senza il tipo l'articolo
        // non avrebbe mai la propria immagine, perche' la pagina vince.
        Page::create(['title' => 'Guida della pagina', 'slug' => 'guida', 'status' => 'published', 'blocks' => []]);
        $this->articolo('guida', ['title' => 'Guida dell articolo']);

        Sanctum::actingAs($this->redattore, ["site:{$this->sito->id}"]);

        $pagina = $this->get("/api/sites/{$this->sito->domain}/og/guida.png?tipo=pagina");
        $articolo = $this->get("/api/sites/{$this->sito->domain}/og/guida.png?tipo=articolo");

        $pagina->assertOk();
        $articolo->assertOk();

        $this->assertNotSame(
            $pagina->getContent(),
            $articolo->getContent(),
            'Le due immagini sono identiche: il tipo non viene considerato.'
        );
    }

    public function test_l_api_espone_la_base_gia_normalizzata(): void
    {
        $this->sito->forceFill(['layout_config' => ['blog' => ['base' => 'NEWS!']]])->saveQuietly();

        $json = (new \App\Http\Resources\SiteResource($this->sito->fresh()))->toArray(request());

        // Astro non deve ripetere la validazione per poi divergere.
        $this->assertSame('blog', $json['base_blog']);
    }
}
