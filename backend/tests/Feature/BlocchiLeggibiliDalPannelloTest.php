<?php

namespace Tests\Feature;

use App\Filament\Resources\Pages\Pages\EditPage;
use App\Filament\Resources\Pages\Schemas\PageForm;
use App\Models\Page;
use App\Models\Plan;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\ContenutoHomeSlimcms;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * I contenuti salvati devono essere LEGGIBILI dal pannello.
 *
 * Non e' ovvio come sembra: i blocchi erano salvati piatti, con il tipo in una
 * chiave "tipo", mentre il Builder di Filament vuole tipo e dati separati
 * ({type, data}). Il builder non riconosceva nulla e mostrava la pagina SENZA
 * BLOCCHI: chi avesse salvato da li' avrebbe cancellato il contenuto.
 *
 * Nessun test lo intercettava perche' il test di pubblicazione creava i
 * blocchi nel formato giusto, mentre i seeder li scrivevano in quello
 * sbagliato: i due formati non si incontravano mai. Questo test li fa
 * incontrare.
 */
class BlocchiLeggibiliDalPannelloTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $piano = Plan::create(['name' => 'T', 'price_monthly' => 0, 'max_sites' => 5, 'max_storage_gb' => 1]);
        $tenant = Tenant::create(['id' => 'c', 'name' => 'C', 'slug' => 'c', 'status' => 'active', 'plan_id' => $piano->id]);
        $this->site = Site::withoutTenancy()->create(['tenant_id' => $tenant->id, 'domain' => 'c.test', 'name' => 'C']);

        $redattore = User::withoutSitePivotScope()->create(['name' => 'R', 'email' => 'r@r.it', 'password' => bcrypt('x')]);
        $redattore->sites()->attach($this->site, ['role' => 'editor']);

        $this->actingAs($redattore);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($this->site, isQuiet: true);
        $this->site->useAsCurrent();
    }

    public function test_i_blocchi_del_sito_pilota_sono_nel_formato_del_builder(): void
    {
        foreach (ContenutoHomeSlimcms::blocchi() as $i => $blocco) {
            $this->assertArrayHasKey('type', $blocco, "Blocco {$i}: manca la chiave 'type'.");
            $this->assertArrayHasKey('data', $blocco, "Blocco {$i}: manca la chiave 'data'.");
            $this->assertArrayNotHasKey('tipo', $blocco, "Blocco {$i}: usa ancora il formato piatto.");
        }
    }

    /**
     * Ogni tipo salvato deve sopravvivere all'andata e ritorno nel form.
     *
     * Si monta il componente vero invece di ispezionare la struttura interna
     * di Filament: quell'introspezione richiede un container inizializzato e
     * si rompe a ogni aggiornamento, mentre questo verifica il comportamento
     * che ci interessa davvero — il pannello ritrova cio' che c'e' salvato.
     */
    public function test_ogni_tipo_salvato_sopravvive_al_form(): void
    {
        $attesi = array_column(ContenutoHomeSlimcms::blocchi(), 'type');

        $pagina = Page::create([
            'title' => 'Home',
            'slug' => 'home-tipi',
            'status' => 'published',
            'blocks' => ContenutoHomeSlimcms::blocchi(),
        ]);

        $caricati = Livewire::test(EditPage::class, ['record' => $pagina->getRouteKey()])
            ->get('data')['blocks'] ?? [];

        $tipiCaricati = array_values(array_map(
            fn (array $b): ?string => $b['type'] ?? null,
            $caricati
        ));

        $this->assertSame(
            $attesi,
            $tipiCaricati,
            'Un tipo salvato non e stato ritrovato dal form: sarebbe contenuto visibile online e non modificabile.'
        );
    }

    /** La prova che conta: aprire la pagina nel pannello e ritrovarci i blocchi. */
    public function test_aprendo_la_pagina_nel_pannello_i_blocchi_ci_sono(): void
    {
        $pagina = Page::create([
            'title' => 'Home',
            'slug' => 'home',
            'status' => 'published',
            'blocks' => ContenutoHomeSlimcms::blocchi(),
        ]);

        $componente = Livewire::test(EditPage::class, ['record' => $pagina->getRouteKey()]);

        $caricati = $componente->get('data')['blocks'] ?? [];

        $this->assertCount(
            count(ContenutoHomeSlimcms::blocchi()),
            $caricati,
            'Il pannello non ha caricato tutti i blocchi: salvando si cancellerebbe il contenuto.'
        );
    }
}
