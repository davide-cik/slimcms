<?php

namespace Tests\Feature;

use App\Enums\Ruolo;
use App\Filament\Pages\TagECategorie;
use App\Filament\Widgets\TabellaCategorie;
use App\Filament\Widgets\TabellaTag;
use App\Models\Category;
use App\Models\Plan;
use App\Models\Site;
use App\Models\Tag;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Tag e categorie in una pagina sola.
 *
 * Erano due voci di menu per due tabelle da dieci righe, e sono cose che si
 * decidono guardandole insieme. Le risorse restano con le loro rotte e le
 * loro policy: qui cambia solo dove si guardano.
 */
class TagECategorieTest extends TestCase
{
    use RefreshDatabase;

    private Site $sito;

    protected function setUp(): void
    {
        parent::setUp();

        $piano = Plan::create(['name' => 'T', 'price_monthly' => 0, 'max_sites' => 5, 'max_storage_gb' => 1]);
        $tenant = Tenant::create(['id' => 'c', 'name' => 'C', 'slug' => 'c', 'status' => 'active', 'plan_id' => $piano->id]);
        $this->sito = Site::withoutTenancy()->create(['tenant_id' => $tenant->id, 'domain' => 'c.test', 'name' => 'C']);

        $this->sito->useAsCurrent();
        Category::create(['name' => 'Dietro le quinte', 'slug' => 'dietro-le-quinte']);
        Tag::create(['name' => 'Performance', 'slug' => 'performance']);
        Site::forgetCurrent();
    }

    private function entra(Ruolo $ruolo): void
    {
        $u = User::withoutSitePivotScope()->create([
            'name' => 'U', 'email' => $ruolo->value . '@c.test', 'password' => bcrypt('x'),
        ]);
        $u->sites()->attach($this->sito, ['role' => $ruolo->value]);

        $this->actingAs($u);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($this->sito, isQuiet: true);
        $this->sito->useAsCurrent();
    }

    public function test_la_pagina_mostra_tutti_e_due_i_riquadri(): void
    {
        $this->entra(Ruolo::Editor);

        Livewire::test(TagECategorie::class)
            ->assertOk()
            ->assertSee('Tag')
            ->assertSee('Categorie');
    }

    public function test_il_riquadro_dei_tag_elenca_i_tag_del_sito(): void
    {
        $this->entra(Ruolo::Editor);

        Livewire::test(TabellaTag::class)
            ->assertOk()
            ->assertSee('Performance')
            ->assertDontSee('Dietro le quinte');
    }

    public function test_il_riquadro_delle_categorie_elenca_le_categorie(): void
    {
        $this->entra(Ruolo::Editor);

        Livewire::test(TabellaCategorie::class)
            ->assertOk()
            ->assertSee('Dietro le quinte')
            ->assertDontSee('Performance');
    }

    /**
     * Il widget porta la propria query, e quella query deve restare dentro il
     * sito: e' l'unico posto dove il global scope potrebbe non essere
     * applicato per distrazione.
     */
    public function test_i_riquadri_non_mostrano_i_termini_di_un_altro_sito(): void
    {
        $altro = Site::withoutTenancy()->create([
            'tenant_id' => $this->sito->tenant_id, 'domain' => 'altro.test', 'name' => 'Altro',
        ]);

        $altro->useAsCurrent();
        Tag::create(['name' => 'Segretissimo', 'slug' => 'segretissimo']);
        Category::create(['name' => 'Riservata', 'slug' => 'riservata']);
        Site::forgetCurrent();

        $this->entra(Ruolo::Editor);

        Livewire::test(TabellaTag::class)->assertDontSee('Segretissimo');
        Livewire::test(TabellaCategorie::class)->assertDontSee('Riservata');
    }

    /** Le due voci non stanno piu' nella barra laterale: c'e' la pagina unica. */
    public function test_le_due_risorse_non_sono_piu_nel_menu(): void
    {
        $this->assertFalse(\App\Filament\Resources\Tags\TagResource::shouldRegisterNavigation());
        $this->assertFalse(\App\Filament\Resources\Categories\CategoryResource::shouldRegisterNavigation());
    }

    /** Un lettore guarda, come per ogni altro contenuto. */
    public function test_un_lettore_puo_guardare(): void
    {
        $this->entra(Ruolo::Viewer);

        $this->assertTrue(TagECategorie::canAccess());
    }
}
