<?php

namespace Tests\Unit;

use App\Support\Slug;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Gli slug sono indirizzi: una volta pubblicati non si cambiano senza
 * rompere i collegamenti di chi ci e' arrivato. La regola che li produce
 * merita di essere fissata caso per caso.
 */
class SlugTest extends TestCase
{
    #[DataProvider('casi')]
    public function test_lo_slug_e_quello_atteso(string $testo, string $atteso, string $perche): void
    {
        $this->assertSame($atteso, Slug::da($testo), $perche);
    }

    public static function casi(): array
    {
        return [
            // Gli accenti diventano la lettera senza accento, non spariscono.
            'accento grave' => ['Città', 'citta', 'L\'accento va tolto dalla lettera, non la lettera.'],
            'accento acuto' => ['Perché no', 'perche-no', 'Idem con l\'acuto.'],
            'maiuscola accentata' => ['È così', 'e-cosi', 'Anche a inizio parola.'],

            // Il caso che Str::slug da sola sbagliava: in italiano
            // l'apostrofo e' ovunque, e univa le due parole.
            'apostrofo dritto' => ["Sant'Angelo", 'sant-angelo', 'L\'apostrofo separa, non unisce.'],
            'apostrofo tipografico' => ['L’azienda', 'l-azienda', 'Anche quello curvo che inseriscono gli editor.'],
            'apostrofo finale' => ["un po' di tutto", 'un-po-di-tutto', 'Un po\' non deve diventare unpo.'],

            // Stessa cosa per barre e trattini lunghi.
            'barra' => ['Caffè/Tè', 'caffe-te', 'La barra separa due parole.'],
            'trattino lungo' => ['SEO/GEO — 2026', 'seo-geo-2026', 'Il trattino lungo separa.'],
            'punto' => ['info@slimcms.it', 'info-at-slimcms-it', 'La chiocciola diventa at, i punti separano.'],

            // Sostituzioni con un significato, non con un carattere.
            'e commerciale' => ['Attività & Servizi', 'attivita-e-servizi', 'La & si legge "e", non si butta.'],
            'piu' => ['3 + 2', '3-piu-2', 'Il piu si legge.'],

            // Rumore.
            'spazi multipli' => ['  spazi   doppi  ', 'spazi-doppi', 'Gli spazi in eccesso non lasciano trattini.'],
            'emoji' => ['🚀 lancio', 'lancio', 'Un emoji non e\' una parola.'],

            // Niente di utilizzabile: meglio vuoto che inventato. Il form
            // segnala il campo come obbligatorio e chi scrive ne sceglie uno.
            'solo ideogrammi' => ['日本語', '', 'Meglio vuoto che uno slug illeggibile inventato.'],
            'solo emoji' => ['🚀', '', 'Idem.'],
        ];
    }
}
