<?php

namespace App\Console\Commands;

use App\Models\PaginaMancante;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Importa i 404 annotati dal gestore d'errore di ogni sito.
 *
 * Il sito pubblico e' statico e un 404 non tocca Laravel. La pagina d'errore
 * e' pero' un piccolo file PHP che, prima di stampare la pagina, annota
 * l'indirizzo richiesto in una cartella privata del sito. Questo comando
 * legge quelle righe e le aggrega qui.
 *
 * Il file viene consumato: si legge, si importa, si svuota. Cosi' non serve
 * ricordare dove si era arrivati — che e' il modo tipico in cui un monitor
 * muore dopo una rotazione, restando apparentemente vivo.
 */
class ImportaPagineMancanti extends Command
{
    protected $signature = 'slimcms:importa-404
                            {--site= : Un solo sito, per dominio}
                            {--secco : Mostra cosa importerebbe senza scrivere}';

    protected $description = 'Importa i 404 annotati dai siti e li aggrega nel pannello';

    public function handle(): int
    {
        $siti = Site::withoutTenancy()
            ->when($this->option('site'), fn ($q, $d) => $q->where('domain', $d))
            ->get();

        if ($siti->isEmpty()) {
            $this->warn('Nessun sito da esaminare.');

            return self::SUCCESS;
        }

        $totale = 0;

        foreach ($siti as $sito) {
            $totale += $this->perSito($sito);
        }

        $this->info("Fatto: {$totale} indirizzi aggiornati.");

        return self::SUCCESS;
    }

    private function perSito(Site $sito): int
    {
        $registro = $this->percorsoRegistro($sito);

        if (! is_file($registro)) {
            $this->line("  {$sito->domain}: nessuna annotazione.");

            return 0;
        }

        // Si rinomina prima di leggere: le richieste che arrivano nel
        // frattempo scrivono nel file nuovo e non vengono perse, e non serve
        // tenere un lock per tutta la durata dell'importazione.
        $lavorazione = $registro . '.' . now()->format('YmdHis');

        if (! @rename($registro, $lavorazione)) {
            $this->warn("  {$sito->domain}: non riesco a prendere in carico {$registro}.");

            return 0;
        }

        $aggregati = $this->aggrega($lavorazione);

        if ($this->option('secco')) {
            // In prova si rimette dov'era: un --secco che consuma i dati
            // sarebbe una prova che cambia cio' che sta provando.
            @rename($lavorazione, $registro);
            $this->line("  {$sito->domain}: importerebbe " . count($aggregati) . ' indirizzi.');

            return 0;
        }

        $sito->useAsCurrent();

        foreach ($aggregati as $percorso => $dati) {
            $this->salva($sito, $percorso, $dati);
        }

        @unlink($lavorazione);
        $this->line("  {$sito->domain}: " . count($aggregati) . ' indirizzi.');

        return count($aggregati);
    }

    /**
     * @return array<string, array{colpi: int, con_referrer: int, referrer: ?string, primo: ?string, ultimo: ?string}>
     */
    private function aggrega(string $file): array
    {
        $aggregati = [];
        $handle = fopen($file, 'rb');

        while (($riga = fgets($handle)) !== false) {
            $voce = json_decode($riga, true);

            // Una riga troncata da una scrittura interrotta non deve far
            // saltare l'intera importazione.
            if (! is_array($voce) || ! isset($voce['p']) || ! is_string($voce['p'])) {
                continue;
            }

            // La query string va via: /cerca?q=uno e /cerca?q=due sono lo
            // stesso indirizzo mancante.
            $percorso = (string) parse_url($voce['p'], PHP_URL_PATH);

            if ($percorso === '' || mb_strlen($percorso) > 500) {
                continue;
            }

            $referrer = is_string($voce['r'] ?? null) && $voce['r'] !== '' ? $voce['r'] : null;
            $quando = is_string($voce['q'] ?? null) ? $voce['q'] : null;

            $corrente = $aggregati[$percorso] ?? [
                'colpi' => 0, 'con_referrer' => 0, 'referrer' => null,
                'primo' => $quando, 'ultimo' => $quando,
            ];

            $corrente['colpi']++;
            $corrente['ultimo'] = $quando ?? $corrente['ultimo'];

            if ($referrer !== null) {
                $corrente['con_referrer']++;
                $corrente['referrer'] = $referrer;
            }

            $aggregati[$percorso] = $corrente;
        }

        fclose($handle);

        return $aggregati;
    }

    private function salva(Site $sito, string $percorso, array $dati): void
    {
        // I contatori si sommano a quelli gia' presenti, quindi l'operazione
        // deve essere atomica: due passate sovrapposte non devono perdere
        // colpi l'una dell'altra.
        DB::transaction(function () use ($sito, $percorso, $dati) {
            $riga = PaginaMancante::withoutSiteScope()
                ->where('site_id', $sito->id)
                ->where('percorso', $percorso)
                ->lockForUpdate()
                ->first();

            if ($riga === null) {
                PaginaMancante::withoutSiteScope()->create([
                    'site_id' => $sito->id,
                    'percorso' => $percorso,
                    'colpi' => $dati['colpi'],
                    'colpi_con_referrer' => $dati['con_referrer'],
                    'ultimo_referrer' => $dati['referrer'],
                    'primo_il' => $dati['primo'],
                    'ultimo_il' => $dati['ultimo'],
                ]);

                return;
            }

            $riga->update([
                'colpi' => $riga->colpi + $dati['colpi'],
                'colpi_con_referrer' => $riga->colpi_con_referrer + $dati['con_referrer'],
                'ultimo_referrer' => $dati['referrer'] ?? $riga->ultimo_referrer,
                'ultimo_il' => $dati['ultimo'] ?? $riga->ultimo_il,
            ]);
        });
    }

    public function percorsoRegistro(Site $sito): string
    {
        return str_replace('{dominio}', $sito->domain, (string) config('slimcms.registro_404'));
    }
}
