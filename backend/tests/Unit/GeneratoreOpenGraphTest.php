<?php

namespace Tests\Unit;

use App\Models\Site;
use App\Services\GeneratoreOpenGraph;
use Tests\TestCase;

class GeneratoreOpenGraphTest extends TestCase
{
    private GeneratoreOpenGraph $gen;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gen = new GeneratoreOpenGraph();
    }

    private function sito(array $og = []): Site
    {
        return new Site([
            'name' => 'SlimCMS',
            'domain' => 'slimcms.it',
            'theme' => ['carta' => '#f4f4f1', 'inchiostro' => '#16181c', 'segnale' => '#0f6b4a'],
            'og_config' => $og + ['payoff' => 'Un CMS per chi gestisce venti siti.', 'cta' => 'Visita il nostro sito'],
        ]);
    }

    private function dimensioniPng(string $png): array
    {
        return array_values(unpack('Nl/Na', substr($png, 16, 8)));
    }

    public function test_dimensioni_predefinite_verticali(): void
    {
        $d = $this->gen->dimensioni($this->sito());

        $this->assertSame(1200, $d['larghezza']);
        $this->assertSame(1600, $d['altezza']);
    }

    public function test_ogni_sito_puo_scegliere_le_proprie_dimensioni(): void
    {
        $d = $this->gen->dimensioni($this->sito(['larghezza' => 1200, 'altezza' => 630]));

        $this->assertSame(630, $d['altezza']);
    }

    /** Valori assurdi non devono poter generare immagini da centinaia di MB. */
    public function test_le_dimensioni_sono_limitate(): void
    {
        $enorme = $this->gen->dimensioni($this->sito(['larghezza' => 99999, 'altezza' => 99999]));
        $minuscolo = $this->gen->dimensioni($this->sito(['larghezza' => 1, 'altezza' => 1]));

        $this->assertSame(2400, $enorme['larghezza']);
        $this->assertSame(600, $minuscolo['altezza']);
    }

    /**
     * La fascia sicura e' il cuore del progetto: e' la parte che sopravvive
     * al ritaglio 1.91:1 di Facebook e LinkedIn.
     */
    public function test_la_fascia_sicura_e_centrata_e_in_rapporto_191(): void
    {
        $f = $this->gen->fasciaSicura(1200, 1600);

        $this->assertSame(628, $f['altezza']);
        $this->assertEqualsWithDelta(1.91, 1200 / $f['altezza'], 0.01);
        // Centrata: sopra e sotto lo stesso spazio.
        $this->assertSame(486, $f['alto']);
        $this->assertSame(486, 1600 - $f['alto'] - $f['altezza']);
    }

    /** Su una tela gia' orizzontale non c'e' niente da proteggere. */
    public function test_su_tela_orizzontale_la_fascia_copre_tutto(): void
    {
        $f = $this->gen->fasciaSicura(1200, 500);

        $this->assertSame(500, $f['altezza']);
        $this->assertSame(0, $f['alto']);
    }

    public function test_produce_un_png_delle_dimensioni_richieste(): void
    {
        $png = $this->gen->png($this->sito(), 'Perché abbiamo lasciato WordPress');

        $this->assertSame("\x89PNG", substr($png, 0, 4));
        $this->assertSame([1200, 1600], $this->dimensioniPng($png));
    }

    public function test_il_ritaglio_ha_il_rapporto_dei_social(): void
    {
        $png = $this->gen->pngRitagliato($this->sito(), 'Titolo');
        [$w, $h] = $this->dimensioniPng($png);

        $this->assertSame(1200, $w);
        $this->assertSame(628, $h);
    }

    /** Un titolo lunghissimo non deve far esplodere il generatore ne' uscire
     *  dal riquadro: scende di corpo e va a capo. */
    public function test_regge_un_titolo_molto_lungo(): void
    {
        $lungo = str_repeat('Parola ', 40);
        $png = $this->gen->png($this->sito(), $lungo);

        $this->assertSame([1200, 1600], $this->dimensioniPng($png));
    }

    public function test_funziona_anche_senza_configurazione(): void
    {
        $spoglio = new Site(['name' => 'Sito', 'domain' => 'sito.test']);
        $png = $this->gen->png($spoglio, 'Titolo');

        $this->assertSame([1200, 1600], $this->dimensioniPng($png));
    }
}
