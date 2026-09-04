<?php

namespace Tests\Unit;

use App\Services\Rilasci;
use Tests\TestCase;

class RilasciTest extends TestCase
{
    /** Output di git finto, nello stesso formato che usa il servizio. */
    private function outputGit(array $commit): string
    {
        return collect($commit)
            ->map(fn (array $c) => implode("\x1f", [$c[0], $c[1], $c[2], $c[3], $c[4] ?? '']))
            ->implode("\x1e") . "\x1e";
    }

    public function test_numera_partendo_dal_commit_piu_vecchio(): void
    {
        $r = Rilasci::daOutputGit($this->outputGit([
            ['ccc', 'Davide', '2026-09-04T12:00:00+02:00', 'terzo'],
            ['bbb', 'Davide', '2026-09-03T12:00:00+02:00', 'secondo'],
            ['aaa', 'Davide', '2026-09-02T12:00:00+02:00', 'primo'],
        ]));

        // Il piu' recente ha il numero piu' alto, il piu' vecchio e' 0.0.1:
        // cosi' un commit non cambia mai numero quando ne arrivano altri.
        $this->assertSame('0.0.3', $r[0]['versione']);
        $this->assertSame('0.0.1', $r[2]['versione']);
        $this->assertSame('primo', $r[2]['titolo']);
    }

    public function test_estrae_i_campi_del_commit(): void
    {
        $r = Rilasci::daOutputGit($this->outputGit([
            ['abc1234567890', 'Davide', '2026-09-04T12:00:00+02:00', 'Titolo', "Corpo su\npiu righe"],
        ]));

        $this->assertSame('abc1234', $r[0]['hash_breve']);
        $this->assertSame('Davide', $r[0]['autore']);
        $this->assertSame('Titolo', $r[0]['titolo']);
        $this->assertSame("Corpo su\npiu righe", $r[0]['corpo']);
    }

    /** Un messaggio di commit puo' contenere qualunque carattere stampabile:
     *  i separatori sono di controllo proprio per questo. */
    public function test_regge_messaggi_con_caratteri_insidiosi(): void
    {
        $r = Rilasci::daOutputGit($this->outputGit([
            ['aaa', 'Davide', '2026-09-04T12:00:00+02:00', 'Fix: usa | e ; e "virgolette"', "riga\ncon a capo"],
        ]));

        $this->assertCount(1, $r);
        $this->assertSame('Fix: usa | e ; e "virgolette"', $r[0]['titolo']);
    }

    public function test_output_vuoto_non_esplode(): void
    {
        $this->assertCount(0, Rilasci::daOutputGit(''));
        $this->assertCount(0, Rilasci::daOutputGit("\n"));
    }

    public function test_pagina_da_cinquanta(): void
    {
        $commit = [];
        for ($i = 120; $i >= 1; $i--) {
            $commit[] = ["hash{$i}", 'Davide', '2026-09-04T12:00:00+02:00', "commit {$i}"];
        }

        $servizio = new class($commit, $this) extends Rilasci {
            public function __construct(private array $c, private $t) {}
            public function tutti(): \Illuminate\Support\Collection
            {
                return static::daOutputGit(collect($this->c)
                    ->map(fn ($x) => implode("\x1f", [...$x, '']))
                    ->implode("\x1e"));
            }
        };

        $this->assertSame(3, $servizio->pagineTotali());
        $this->assertCount(50, $servizio->pagina(1));
        $this->assertCount(20, $servizio->pagina(3));
        $this->assertSame('0.0.120', $servizio->pagina(1)->first()['versione']);
        $this->assertSame('0.0.1', $servizio->pagina(3)->last()['versione']);
    }
}
