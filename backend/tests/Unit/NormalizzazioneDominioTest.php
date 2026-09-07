<?php

namespace Tests\Unit;

use App\ControlPlane\Filament\Resources\Sites\SiteResource;
use App\Models\Site;
use Tests\TestCase;

/**
 * Regressione su un bug che ha distrutto un dato in produzione.
 *
 * Il campo "dominio" viene normalizzato al salvataggio (minuscolo, senza
 * spazi). Con un parametro di closure che Filament non sapeva risolvere, alla
 * normalizzazione arrivava null: il risultato era stringa vuota e il dominio
 * del sito veniva sovrascritto. Il sito diventava irraggiungibile dal pannello
 * (chiave di rotta vuota, 404 sulla modifica e 500 sulla lista) e sparivano i
 * riferimenti usati dalla mappa di routing.
 *
 * La validazione 'required' non protegge: gira PRIMA della trasformazione.
 */
class NormalizzazioneDominioTest extends TestCase
{
    public function test_normalizza_maiuscole_e_spazi(): void
    {
        $this->assertSame('cliente.it', SiteResource::normalizzaDominio('  Cliente.IT  '));
        $this->assertSame('blog.cliente.it', SiteResource::normalizzaDominio('BLOG.Cliente.it'));
    }

    /** Il caso che ha rotto la produzione. */
    public function test_non_svuota_mai_un_dominio_gia_salvato(): void
    {
        $sito = new Site(['domain' => 'gia-salvato.it']);

        $this->assertSame(
            'gia-salvato.it',
            SiteResource::normalizzaDominio(null, $sito),
            'Uno stato null non deve azzerare il dominio.'
        );

        $this->assertSame(
            'gia-salvato.it',
            SiteResource::normalizzaDominio('   ', $sito),
            'Uno stato di soli spazi non deve azzerare il dominio.'
        );
    }

    /** In creazione non c'e' un record da cui recuperare: null e' corretto,
     *  e la validazione 'required' lo intercetta prima del salvataggio. */
    public function test_in_creazione_restituisce_null_senza_record(): void
    {
        $this->assertNull(SiteResource::normalizzaDominio('', null));
    }

    /**
     * Il `www.` va tolto davvero.
     *
     * `RisolviSitoDaParametro` lo toglie dall'indirizzo in arrivo e confronta
     * con `sites.domain`: un sito salvato come `www.cliente.it` non verrebbe
     * trovato da nessuna richiesta, e il sintomo sarebbe un 404 su tutto
     * senza niente di rotto da nessuna parte. Prima era solo scritto nel
     * testo d'aiuto del campo.
     */
    public function test_toglie_www_e_schema(): void
    {
        $this->assertSame('cliente.it', SiteResource::normalizzaDominio('www.cliente.it'));
        $this->assertSame('cliente.it', SiteResource::normalizzaDominio('https://www.Cliente.it/'));
        $this->assertSame('cliente.it', SiteResource::normalizzaDominio('http://cliente.it'));

        // Un sottodominio che si chiama davvero cosi' non va mutilato.
        $this->assertSame('wwwx.cliente.it', SiteResource::normalizzaDominio('wwwx.cliente.it'));
    }
}
