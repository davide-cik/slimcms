<?php

namespace Tests\Feature;

use App\Filament\Resources\Pages\Pages\EditPage;
use App\Http\Resources\PageResource;
use App\Models\Page;
use App\Models\Plan;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Le immagini dei blocchi devono arrivare al frontend con url e alt.
 *
 * Prima non ci arrivavano affatto: SpatieMediaLibraryFileUpload dentro un
 * blocco del Builder allega il file alla pagina e poi CANCELLA la chiave
 * dallo stato del blocco. Il blocco restava senza alcun riferimento, la
 * galleria online era un contenitore vuoto e due gallerie sulla stessa
 * pagina si sarebbero viste addosso le immagini l'una dell'altra.
 *
 * Ora la libreria sta fuori dal builder e i blocchi salvano l'uuid; il
 * PageResource lo risolve. Questo test copre il giro intero.
 */
class MediaNeiBlocchiTest extends TestCase
{
    use RefreshDatabase;

    private Page $pagina;

    protected function setUp(): void
    {
        parent::setUp();

        $piano = Plan::create(['name' => 'T', 'price_monthly' => 0, 'max_sites' => 5, 'max_storage_gb' => 1]);
        $tenant = Tenant::create(['id' => 'c', 'name' => 'C', 'slug' => 'c', 'status' => 'active', 'plan_id' => $piano->id]);
        $site = Site::withoutTenancy()->create(['tenant_id' => $tenant->id, 'domain' => 'c.test', 'name' => 'C']);

        $redattore = User::withoutSitePivotScope()->create(['name' => 'R', 'email' => 'r@r.it', 'password' => bcrypt('x')]);
        $redattore->sites()->attach($site, ['role' => 'editor']);

        $this->actingAs($redattore);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($site, isQuiet: true);
        $site->useAsCurrent();

        $this->pagina = Page::create(['title' => 'Con foto', 'slug' => 'con-foto', 'blocks' => []]);
    }

    /** Carica un'immagine nella libreria della pagina e ne restituisce l'uuid. */
    private function caricaImmagine(string $alt): string
    {
        $media = $this->pagina
            ->addMedia(UploadedFile::fake()->image('foto.jpg', 400, 300))
            ->withCustomProperties(['alt' => $alt])
            ->toMediaCollection('immagini');

        return $media->uuid;
    }

    public function test_una_galleria_esce_dall_api_con_url_e_alt(): void
    {
        $uuid = $this->caricaImmagine('Una foto del laboratorio');

        $this->pagina->update([
            'blocks' => [['type' => 'galleria', 'data' => ['titolo' => 'Le foto', 'media' => [$uuid]]]],
        ]);

        $blocchi = (new PageResource($this->pagina->fresh()))->toArray(request())['blocks'];
        $immagini = $blocchi[0]['data']['media'];

        $this->assertCount(1, $immagini);
        $this->assertArrayHasKey('url', $immagini[0], 'L\'uuid non e\' stato risolto in un file.');
        $this->assertSame('Una foto del laboratorio', $immagini[0]['alt']);
    }

    public function test_due_blocchi_tengono_immagini_diverse(): void
    {
        // E' il caso che prima era impossibile: la stessa collezione per
        // tutti, e nessun riferimento per distinguere.
        $primo = $this->caricaImmagine('Prima');
        $secondo = $this->caricaImmagine('Seconda');

        $this->pagina->update([
            'blocks' => [
                ['type' => 'galleria', 'data' => ['media' => [$primo]]],
                ['type' => 'immagine_testo', 'data' => ['media' => $secondo, 'titolo' => 'Con testo']],
            ],
        ]);

        $blocchi = (new PageResource($this->pagina->fresh()))->toArray(request())['blocks'];

        $this->assertSame('Prima', $blocchi[0]['data']['media'][0]['alt']);
        // Singola, non lista: il blocco immagine e testo ne ha una sola.
        $this->assertSame('Seconda', $blocchi[1]['data']['media']['alt']);
    }

    public function test_un_uuid_che_non_esiste_piu_non_rompe_la_pagina(): void
    {
        // Un file cancellato dalla libreria lascia il riferimento dietro di
        // se': la pagina deve continuare a costruirsi.
        $this->pagina->update([
            'blocks' => [['type' => 'galleria', 'data' => ['media' => ['00000000-0000-0000-0000-000000000000']]]],
        ]);

        $blocchi = (new PageResource($this->pagina->fresh()))->toArray(request())['blocks'];

        $this->assertSame(['00000000-0000-0000-0000-000000000000'], $blocchi[0]['data']['media']);
    }

    public function test_i_blocchi_nuovi_si_salvano_dal_pannello(): void
    {
        Livewire::test(EditPage::class, ['record' => $this->pagina->getRouteKey()])
            ->fillForm(['blocks' => [
                ['type' => 'citazione', 'data' => ['testo' => 'Funziona.', 'autore' => 'Una cliente', 'ruolo' => 'Titolare']],
                ['type' => 'numeri', 'data' => ['voci' => [['valore' => '24', 'etichetta' => 'siti gestiti']]]],
                ['type' => 'incorpora', 'data' => ['url' => 'https://www.youtube.com/watch?v=abc123', 'titolo' => 'La presentazione']],
                ['type' => 'contatti', 'data' => ['email' => 'ciao@c.test', 'telefono' => '+39 000 000']],
                ['type' => 'separatore', 'data' => ['stile' => 'linea']],
            ]])
            ->call('save')
            ->assertHasNoFormErrors();

        $tipi = array_column($this->pagina->fresh()->blocks, 'type');

        $this->assertSame(['citazione', 'numeri', 'incorpora', 'contatti', 'separatore'], $tipi);
    }

    public function test_un_indirizzo_non_supportato_non_si_incorpora(): void
    {
        Livewire::test(EditPage::class, ['record' => $this->pagina->getRouteKey()])
            ->fillForm(['blocks' => [
                ['type' => 'incorpora', 'data' => ['url' => 'https://esempio.it/video', 'titolo' => 'X']],
            ]])
            ->call('save')
            ->assertHasFormErrors();
    }
}
