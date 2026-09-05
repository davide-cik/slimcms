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
 * Il contratto fra il pannello e il sito pubblico.
 *
 * Quasi tutti i guasti di questo progetto sono nati sulla stessa giuntura:
 * due meta' scritte in momenti diversi, ciascuna verificata contro l'IDEA
 * dell'altra invece che contro l'altra. I blocchi salvati piatti mentre il
 * form li voleva annidati, il blocco `capacita` usato dal contenuto e assente
 * dal form, la galleria che non teneva alcun riferimento alle immagini: tre
 * volte lo stesso errore in punti diversi. Due meta' sbagliate nello stesso
 * modo passano tutti i test scritti per lato.
 *
 * Questo test le fa incontrare: prende l'elenco dei blocchi dal pannello, che
 * ne e' la fonte, e pretende che ognuno sia reso da Astro. Un blocco nuovo
 * che nessuno rende, o reso e non piu' redigibile, fallisce qui.
 */
class ContrattoBlocchiTest extends TestCase
{
    use RefreshDatabase;

    private const BLOCCHI_ASTRO = __DIR__ . '/../../../frontend/src/components/blocchi/Blocchi.astro';

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

        $this->pagina = Page::create(['title' => 'Contratto', 'slug' => 'contratto', 'blocks' => []]);
    }

    /**
     * I nomi dei blocchi come li dichiara il pannello.
     *
     * @return list<string>
     */
    private function tipiDelPannello(): array
    {
        $componente = Livewire::test(EditPage::class, ['record' => $this->pagina->getRouteKey()]);

        $builder = $componente->instance()->form->getComponent(
            fn ($c) => $c instanceof \Filament\Forms\Components\Builder && $c->getName() === 'blocks'
        );

        $this->assertNotNull($builder, 'Il builder dei blocchi non e\' nel form.');

        return array_map(fn ($b) => $b->getName(), $builder->getBlocks());
    }

    public function test_ogni_blocco_del_pannello_e_reso_da_astro(): void
    {
        $sorgente = file_get_contents(self::BLOCCHI_ASTRO);

        $this->assertNotFalse($sorgente, 'Blocchi.astro non trovato: il percorso del monorepo e\' cambiato?');

        $senzaRendering = array_values(array_filter(
            $this->tipiDelPannello(),
            fn (string $tipo): bool => ! str_contains($sorgente, "case '{$tipo}':")
        ));

        $this->assertSame([], $senzaRendering, implode(' ', [
            'Questi blocchi si possono redigere ma il sito non li mostra:',
            implode(', ', $senzaRendering) . '.',
            'Il redattore li vedrebbe salvati e invisibili online.',
        ]));
    }

    public function test_ogni_blocco_reso_da_astro_e_redigibile(): void
    {
        $sorgente = (string) file_get_contents(self::BLOCCHI_ASTRO);
        preg_match_all("/case '([a-z_]+)':/", $sorgente, $trovati);

        $senzaForm = array_values(array_diff($trovati[1], $this->tipiDelPannello()));

        $this->assertSame([], $senzaForm, implode(' ', [
            'Astro rende questi blocchi ma il pannello non li offre:',
            implode(', ', $senzaForm) . '.',
            'Il contenuto sarebbe visibile online e non modificabile — successo con `capacita`.',
        ]));
    }

    public function test_ogni_blocco_sopravvive_al_giro_completo(): void
    {
        $uuid = $this->pagina
            ->addMedia(UploadedFile::fake()->image('foto.jpg', 400, 300))
            ->withCustomProperties(['alt' => 'Una foto'])
            ->toMediaCollection('immagini')
            ->uuid;

        $blocchi = array_map(
            fn (string $tipo): array => ['type' => $tipo, 'data' => $this->datiDiProva($tipo, $uuid)],
            $this->tipiDelPannello()
        );

        // Salvataggio dal pannello: e' il percorso che il redattore usa, ed
        // e' quello che aveva cancellato i contenuti quando i formati non
        // combaciavano.
        Livewire::test(EditPage::class, ['record' => $this->pagina->getRouteKey()])
            ->fillForm(['blocks' => $blocchi])
            ->call('save')
            ->assertHasNoFormErrors();

        $salvati = $this->pagina->fresh()->blocks;

        $this->assertSame(
            array_column($blocchi, 'type'),
            array_column($salvati, 'type'),
            'Il salvataggio dal pannello ha perso o riordinato dei blocchi.'
        );

        // E riaperti nel pannello, ci sono ancora tutti: e' il controllo che
        // mancava quando i blocchi erano salvati in un formato che il Builder
        // non riconosceva e mostrava la pagina vuota.
        $riletti = Livewire::test(EditPage::class, ['record' => $this->pagina->getRouteKey()])
            ->get('data')['blocks'] ?? [];

        $this->assertCount(count($blocchi), $riletti, 'Riaprendo la pagina il pannello non ritrova tutti i blocchi.');

        // E l'API li consegna ad Astro con le immagini risolte.
        $dallApi = (new PageResource($this->pagina->fresh()))->toArray(request())['blocks'];

        $this->assertSame(array_column($blocchi, 'type'), array_column($dallApi, 'type'));

        foreach ($dallApi as $blocco) {
            $media = $blocco['data']['media'] ?? null;

            if ($media === null) {
                continue;
            }

            // Un blocco puo' tenerne una sola (immagine e testo) o un elenco
            // (galleria, loghi): una risolta e' un array associativo, quindi
            // e' la "lista" a distinguere i due casi.
            $elenco = array_is_list($media) ? $media : [$media];

            foreach ($elenco as $immagine) {
                $this->assertIsArray(
                    $immagine,
                    "Il blocco {$blocco['type']} consegna un uuid grezzo: Astro renderebbe un'immagine rotta."
                );
                $this->assertArrayHasKey('url', $immagine);
            }
        }
    }

    /**
     * Dati minimi validi per ogni tipo di blocco.
     *
     * Un tipo nuovo che non compare qui fa fallire il test con un messaggio
     * esplicito: e' il promemoria di coprirlo, non un salto silenzioso.
     *
     * @return array<string, mixed>
     */
    private function datiDiProva(string $tipo, string $uuid): array
    {
        return match ($tipo) {
            'hero' => ['occhiello' => 'Occhiello', 'titolo' => 'Titolo', 'testo' => 'Testo'],
            'testo_ricco' => ['corpo' => '<p>Corpo.</p>'],
            'galleria' => ['titolo' => 'Foto', 'media' => [$uuid]],
            'cta' => ['titolo' => 'Titolo', 'etichetta_bottone' => 'Vai', 'url' => 'https://esempio.it'],
            'capacita' => ['voci' => [['etichetta' => 'E', 'titolo' => 'T', 'testo' => 'Testo', 'macchina' => null]]],
            'immagine_testo' => ['media' => $uuid, 'posizione' => 'sinistra', 'titolo' => 'T', 'corpo' => '<p>C.</p>'],
            'citazione' => ['testo' => 'Detto bene.', 'autore' => 'A', 'ruolo' => 'R'],
            'numeri' => ['voci' => [['valore' => '24', 'etichetta' => 'siti']]],
            'loghi' => ['titolo' => 'Loghi', 'media' => [$uuid]],
            'incorpora' => ['url' => 'https://www.youtube.com/watch?v=abc123', 'titolo' => 'Video'],
            'contatti' => ['titolo' => 'Contatti', 'email' => 'a@b.it', 'telefono' => '+39 0', 'indirizzo' => 'Via', 'orari' => '9-18'],
            'modulo_contatto' => ['titolo' => 'Scrivici', 'testo' => 'Rispondiamo entro un giorno.', 'etichetta' => 'Invia', 'conferma' => 'Grazie!'],
            'separatore' => ['stile' => 'linea'],
            'faq' => ['voci' => [['domanda' => 'D?', 'risposta' => 'R.']]],
            default => $this->fail("Blocco '{$tipo}' senza dati di prova: aggiungili in datiDiProva()."),
        };
    }
}
