<?php

namespace Tests\Unit;

use App\Models\Redirect;
use App\Services\GeneratoreHtaccess;
use PHPUnit\Framework\TestCase;

/**
 * Il .htaccess e' il file piu' pericoloso che pubblichiamo: se e' malformato
 * Apache risponde 500 su TUTTO il sito, non solo sugli indirizzi
 * reindirizzati. Ogni caso che puo' produrne uno rotto sta qui.
 */
class GeneratoreHtaccessTest extends TestCase
{
    private function genera(array $righe): string
    {
        return (new GeneratoreHtaccess())->genera(
            collect($righe)->map(fn (array $r) => new Redirect($r + ['codice' => 301, 'attivo' => true]))
        );
    }

    public function test_la_pagina_di_errore_del_sito_c_e_sempre(): void
    {
        // Senza questa riga ogni cliente mostra il 404 inglese di HestiaCP.
        // Punta al gestore PHP, che oltre a stampare la pagina annota
        // l'indirizzo mancante: e' anche il monitoraggio dei 404.
        $this->assertStringContainsString(
            'ErrorDocument 404 /' . GeneratoreHtaccess::GESTORE_404,
            $this->genera([])
        );
    }

    public function test_una_regola_accetta_l_indirizzo_con_e_senza_slash_finale(): void
    {
        // Altrimenti si finisce con un 301 verso un indirizzo che a sua volta
        // ne fa un altro solo per lo slash: due giri invece di uno.
        $this->assertStringContainsString(
            'RewriteRule ^vecchia/?$ /nuova/ [R=301,L]',
            $this->genera([['da' => '/vecchia', 'a' => '/nuova/']])
        );
    }

    public function test_una_catena_viene_appiattita(): void
    {
        // A->B->C costa due giri di rete a ogni visita, e i motori ne seguono
        // un numero limitato prima di rinunciare.
        $htaccess = $this->genera([
            ['da' => '/a', 'a' => '/b'],
            ['da' => '/b', 'a' => '/c/'],
        ]);

        $this->assertStringContainsString('RewriteRule ^a/?$ /c/ ', $htaccess);
        $this->assertStringNotContainsString('RewriteRule ^a/?$ /b ', $htaccess);
    }

    public function test_una_catena_con_una_tappa_temporanea_resta_temporanea(): void
    {
        $htaccess = (new GeneratoreHtaccess())->genera(collect([
            new Redirect(['da' => '/a', 'a' => '/b', 'codice' => 301, 'attivo' => true]),
            new Redirect(['da' => '/b', 'a' => '/c/', 'codice' => 302, 'attivo' => true]),
        ]));

        $this->assertStringContainsString('RewriteRule ^a/?$ /c/ [R=302,L]', $htaccess);
    }

    public function test_un_anello_viene_tolto_del_tutto(): void
    {
        // Il caso che conta: lasciare l'ultima tappa raggiunta produrrebbe un
        // redirect su se stesso, cioe' un browser che gira finche' non si
        // arrende. Peggio del 404 che si stava evitando.
        $htaccess = $this->genera([
            ['da' => '/a', 'a' => '/b'],
            ['da' => '/b', 'a' => '/a'],
        ]);

        $this->assertStringNotContainsString('RewriteRule', $htaccess);
    }

    public function test_un_rimando_a_se_stesso_viene_tolto(): void
    {
        // Anche nella forma mascherata dallo slash finale.
        $this->assertStringNotContainsString(
            'RewriteRule',
            $this->genera([['da' => '/x', 'a' => '/x/']])
        );
    }

    public function test_una_regola_spenta_non_finisce_nel_file(): void
    {
        $htaccess = (new GeneratoreHtaccess())->genera(collect([
            new Redirect(['da' => '/spenta', 'a' => '/x/', 'codice' => 301, 'attivo' => false]),
        ]));

        $this->assertStringNotContainsString('spenta', $htaccess);
    }

    public function test_uno_spazio_o_un_a_capo_fanno_scartare_la_regola(): void
    {
        // Cintura oltre alle bretelle della validazione nel form: una riga
        // scartata fa perdere un redirect, una riga malformata il sito.
        $htaccess = $this->genera([
            ['da' => '/con spazio', 'a' => '/x/'],
            ['da' => "/con\na-capo", 'a' => '/x/'],
            ['da' => '/buona', 'a' => '/x/'],
        ]);

        $this->assertSame(1, substr_count($htaccess, 'RewriteRule'));
        $this->assertStringContainsString('^buona/?$', $htaccess);
    }

    public function test_i_caratteri_speciali_vengono_protetti(): void
    {
        // Senza, il punto corrisponderebbe a qualsiasi carattere e una
        // parentesi romperebbe la sintassi del file.
        $htaccess = $this->genera([['da' => '/pagina.php', 'a' => '/nuova/']]);

        $this->assertStringContainsString('^pagina\.php/?$', $htaccess);
    }

    public function test_la_regola_non_scatta_se_la_pagina_esiste(): void
    {
        // Un redirect che oscura una pagina pubblicata e' un guasto che si
        // scopre dal cliente, non dal pannello.
        $htaccess = $this->genera([['da' => '/x', 'a' => '/y/']]);

        $this->assertStringContainsString('RewriteCond %{REQUEST_FILENAME} !-f', $htaccess);
        $this->assertStringContainsString('RewriteCond %{REQUEST_FILENAME} !-d', $htaccess);
    }

    public function test_ogni_codice_offerto_dal_pannello_viene_reso(): void
    {
        // La giuntura: il pannello offre 301 e 302, e il generatore deve
        // saperli scrivere entrambi. Un codice offerto e non reso sarebbe una
        // scelta che non produce niente.
        foreach (array_keys(Redirect::CODICI) as $codice) {
            $htaccess = (new GeneratoreHtaccess())->genera(collect([
                new Redirect(['da' => '/x', 'a' => '/y/', 'codice' => $codice, 'attivo' => true]),
            ]));

            $this->assertStringContainsString("[R={$codice},L]", $htaccess, "Il codice {$codice} non viene reso.");
        }
    }

    public function test_il_file_contiene_solo_direttive_previste(): void
    {
        $htaccess = $this->genera([['da' => '/a', 'a' => 'https://esempio.it/x']]);

        foreach (explode("\n", trim($htaccess)) as $riga) {
            $this->assertMatchesRegularExpression(
                '~^(\s*$|#|ErrorDocument |RewriteEngine |RewriteCond |RewriteRule |</?IfModule)~',
                $riga,
                "Riga imprevista nel .htaccess: {$riga}"
            );
        }
    }
}
