<?php

namespace Tests\Feature;

use App\Filament\Resources\Pages\Pages\CreatePage;
use App\Filament\Resources\Pages\Pages\EditPage;
use App\Models\BuildRequest;
use App\Models\Page;
use App\Models\Plan;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Pubblicazione di una pagina dal pannello, dal form fino alla coda di build.
 *
 * Pilota il componente Livewire vero di Filament, non una scorciatoia sul
 * modello: cosi' copre anche cio' che sta solo nel form, cioe' validazione,
 * trasformazioni dello stato e assegnazione implicita del sito. Il campo
 * site_id non e' esposto nel form, quindi se il contesto non arrivasse fino
 * al salvataggio la creazione fallirebbe: e' proprio quello che vogliamo
 * sapere.
 */
class PubblicazioneDalPannelloTest extends TestCase
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

        $redattore = User::withoutSitePivotScope()->create([
            'name' => 'Redattrice',
            'email' => 'redattrice@cliente.test',
            'password' => bcrypt('password'),
        ]);
        $redattore->sites()->attach($this->site, ['role' => 'editor']);

        $this->actingAs($redattore);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($this->site, isQuiet: true);
        $this->site->useAsCurrent();

        // Ogni sito ha una pagina iniziale: senza, la prima pagina creata dal
        // test verrebbe promossa a home e finirebbe sulla radice invece che
        // sul proprio slug. E' il caso reale, non un dettaglio del test.
        Page::create(['title' => 'Home', 'slug' => 'home', 'is_home' => true]);

        // La creazione del sito ne accoda gia' una: azzeriamo per contare
        // solo quelle prodotte dalla pubblicazione.
        BuildRequest::query()->delete();
    }

    public function test_pubblicare_una_pagina_la_crea_e_accoda_una_build(): void
    {
        Livewire::test(CreatePage::class)
            ->fillForm([
                'title' => 'Chi siamo',
                'slug' => 'chi-siamo',
                'status' => 'published',
                'blocks' => [
                    ['type' => 'hero', 'data' => ['titolo' => 'Chi siamo', 'testo' => 'Due righe.']],
                ],
                'seo' => ['meta_title' => 'Chi siamo', 'meta_description' => 'Pagina di prova.'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $pagina = Page::where('slug', 'chi-siamo')->first();

        $this->assertNotNull($pagina, 'La pagina non e stata creata.');
        // site_id non e' un campo del form: se non fosse arrivato dal contesto
        // BelongsToSite avrebbe sollevato un'eccezione invece di scrivere NULL.
        $this->assertSame($this->site->id, $pagina->site_id);
        $this->assertSame('published', $pagina->status);

        $build = BuildRequest::where('site_id', $this->site->id)->latest('id')->first();

        $this->assertNotNull($build, 'Pubblicare non ha accodato nessuna build.');
        $this->assertSame('pending', $build->status);
        $this->assertContains('/chi-siamo', $build->paths ?? []);
    }

    /** Una bozza non cambia il sito pubblico: rigenerarlo sarebbe lavoro sprecato. */
    public function test_salvare_una_bozza_non_accoda_nessuna_build(): void
    {
        Livewire::test(CreatePage::class)
            ->fillForm([
                'title' => 'Appunti',
                'slug' => 'appunti',
                'status' => 'draft',
                'blocks' => [],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertNotNull(Page::where('slug', 'appunti')->first());
        $this->assertSame(0, BuildRequest::where('site_id', $this->site->id)->count());
    }

    public function test_pubblicare_una_bozza_esistente_accoda_una_build(): void
    {
        $pagina = Page::create(['title' => 'Servizi', 'slug' => 'servizi', 'status' => 'draft']);
        BuildRequest::query()->delete();

        Livewire::test(EditPage::class, ['record' => $pagina->getRouteKey()])
            ->fillForm(['status' => 'published'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('published', $pagina->fresh()->status);
        $this->assertSame(1, BuildRequest::where('site_id', $this->site->id)->count());
    }
}
