<?php

namespace Tests\Unit;

use App\Support\Euro;
use PHPUnit\Framework\TestCase;

class EuroTest extends TestCase
{
    public function test_legge_gli_importi_come_li_scrive_una_persona(): void
    {
        $this->assertSame(3900, Euro::inCentesimi('39'));
        $this->assertSame(3990, Euro::inCentesimi('39,90'));
        $this->assertSame(3990, Euro::inCentesimi('39.90'));
        $this->assertSame(123450, Euro::inCentesimi('1.234,50'));
        $this->assertSame(3990, Euro::inCentesimi(' € 39,90 '));
        // 0,29 * 100 in virgola mobile fa 28,999...: senza arrotondamento
        // diventerebbe 28 centesimi.
        $this->assertSame(29, Euro::inCentesimi('0,29'));
    }

    public function test_quello_che_non_e_un_importo_e_null(): void
    {
        $this->assertNull(Euro::inCentesimi(null));
        $this->assertNull(Euro::inCentesimi(''));
        $this->assertNull(Euro::inCentesimi('trenta'));
    }

    /**
     * Senza virgola, un punto seguito da ESATTAMENTE tre cifre e'
     * separatore delle migliaia, non decimale: "1.500" e' millecinquecento
     * euro, non uno virgola cinque.
     */
    public function test_i_punti_senza_virgola_sono_le_migliaia_a_gruppi_di_tre(): void
    {
        $this->assertSame(150000, Euro::inCentesimi('1.500'));
        $this->assertSame(1200000, Euro::inCentesimi('12.000'));
        $this->assertSame(123400, Euro::inCentesimi('1.234'));
    }

    /**
     * Un punto che non forma un gruppo di tre cifre resta il separatore
     * decimale, come prima di questa regola.
     */
    public function test_un_punto_che_non_e_una_migliaia_resta_decimale(): void
    {
        $this->assertSame(3990, Euro::inCentesimi('39.90'));
        $this->assertSame(3990, Euro::inCentesimi('39.9'));
    }

    /**
     * Lo spazio non-interrompibile (U+00A0) compare incollando un prezzo da
     * una pagina web: va tolto come uno spazio normale.
     */
    public function test_lo_spazio_non_interrompibile_si_toglie_come_uno_spazio_normale(): void
    {
        $this->assertSame(3990, Euro::inCentesimi("39,90\xC2\xA0"));
        $this->assertSame(3990, Euro::inCentesimi("\xC2\xA039,90"));
    }

    /**
     * Un importo negativo o in notazione esponenziale non deve arrivare a
     * MariaDB: la colonna e' un unsignedInteger, e ci arriverebbe come un
     * 500 al posto di un errore nel campo.
     */
    public function test_un_segno_o_una_notazione_esponenziale_e_null(): void
    {
        $this->assertNull(Euro::inCentesimi('-5'));
        $this->assertNull(Euro::inCentesimi('-39,90'));
        $this->assertNull(Euro::inCentesimi('+39,90'));
        $this->assertNull(Euro::inCentesimi('1e20'));
        $this->assertNull(Euro::inCentesimi('1e3'));
    }

    /**
     * Tre cifre decimali dopo la virgola non si arrotondano in silenzio:
     * sono un importo illeggibile, non "39,99" con uno zero in piu'.
     */
    public function test_piu_di_due_decimali_dopo_la_virgola_e_un_importo_illeggibile(): void
    {
        $this->assertNull(Euro::inCentesimi('39,999'));
    }

    public function test_scrive_i_centesimi_all_italiana(): void
    {
        $this->assertSame('39,00', Euro::daCentesimi(3900));
        $this->assertSame('1234,50', Euro::daCentesimi(123450));
        $this->assertNull(Euro::daCentesimi(null));
    }
}
