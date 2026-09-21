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

    public function test_scrive_i_centesimi_all_italiana(): void
    {
        $this->assertSame('39,00', Euro::daCentesimi(3900));
        $this->assertSame('1234,50', Euro::daCentesimi(123450));
        $this->assertNull(Euro::daCentesimi(null));
    }
}
