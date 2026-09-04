<?php

namespace Tests\Unit;

use App\Models\Site;
use App\Services\GeneratoreFavicon;
use Tests\TestCase;

class GeneratoreFaviconTest extends TestCase
{
    private GeneratoreFavicon $gen;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gen = new GeneratoreFavicon();
    }

    public function test_ricava_le_iniziali_dal_nome(): void
    {
        $this->assertSame('SR', $this->gen->dalNome('Studio Rossi'));
        $this->assertSame('S', $this->gen->dalNome('SlimCMS'));
        $this->assertSame('CK', $this->gen->dalNome('Content is King'));
    }

    /** Gli articoli non sono l'identita' del sito: meta' dei siti italiani
     *  avrebbe altrimenti una "I" o una "L". */
    public function test_salta_articoli_e_preposizioni(): void
    {
        $this->assertSame('G', $this->gen->dalNome('Il Girasole'));
        $this->assertSame('CB', $this->gen->dalNome('La Casa del Borgo'));
    }

    public function test_gestisce_nomi_strani_senza_esplodere(): void
    {
        $this->assertSame('?', $this->gen->dalNome('   '));
        $this->assertSame('3', $this->gen->dalNome('3B'));
        $this->assertSame('MB', $this->gen->dalNome('mario-bianchi.it'));
    }

    public function test_le_iniziali_scelte_a_mano_hanno_la_precedenza(): void
    {
        $site = new Site(['name' => 'Studio Rossi', 'favicon_initials' => 'x']);

        $this->assertSame('X', $this->gen->iniziali($site));
    }

    public function test_produce_svg_valido_con_le_iniziali_dentro(): void
    {
        $svg = $this->gen->svg(new Site(['name' => 'Studio Rossi']));

        $this->assertNotFalse(simplexml_load_string($svg), 'L\'SVG generato non e XML valido.');
        $this->assertStringContainsString('>SR<', $svg);
    }

    /** Un colore non valido nel tema non deve poter finire dentro l'SVG. */
    public function test_ignora_colori_non_validi_nel_tema(): void
    {
        $site = new Site([
            'name' => 'Prova',
            'theme' => ['segnale' => '"><script>alert(1)</script>'],
        ]);

        $svg = $this->gen->svg($site);

        $this->assertStringNotContainsString('<script>', $svg);
        $this->assertStringContainsString('#0f6b4a', $svg, 'Doveva ricadere sul colore di default.');
    }

    public function test_il_nome_del_sito_e_messo_in_escape(): void
    {
        $svg = $this->gen->svg(new Site(['name' => 'Rossi & "Figli"']));

        $this->assertNotFalse(simplexml_load_string($svg));
        $this->assertStringNotContainsString('& "', $svg);
    }
}
