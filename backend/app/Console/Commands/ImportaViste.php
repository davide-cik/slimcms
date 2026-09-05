<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Models\Vista;
use App\Models\VistaImpronta;
use App\Support\ClassificatoreAgente;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Porta nel database le visite annotate dal contatore sui siti.
 *
 * Stessa disciplina del registro dei 404: il file viene **consumato** —
 * rinominato, letto, cancellato — invece di tenere una posizione di lettura.
 * Ricordarsi dove si era arrivati e' il modo tipico in cui un monitor muore
 * dopo una rotazione restando apparentemente vivo. Il rename avviene *prima*
 * della lettura, quindi le visite che arrivano nel frattempo scrivono nel
 * file nuovo e non si perdono.
 *
 * L'aggregazione si fa qui e non a ogni interrogazione del pannello: una
 * tabella con una riga per richiesta cresce senza limite e va comunque
 * raggruppata ogni volta.
 *
 * L'indirizzo IP **non** viene salvato: serve solo a calcolare un'impronta
 * con un sale che cambia ogni giorno, e poi si butta. Cosi' si contano i
 * visitatori distinti di oggi senza poterli riconoscere domani.
 */
class ImportaViste extends Command
{
    protected $signature = 'slimcms:importa-viste
        {--site= : limita a un dominio}
        {--secco : leggi senza scrivere, e rimetti a posto il file}';

    protected $description = 'Importa le visite annotate dai siti e le aggrega per giorno';

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

        $this->info("Fatto: {$totale} visite importate.");

        return self::SUCCESS;
    }

    private function perSito(Site $sito): int
    {
        $registro = $this->percorsoRegistro($sito);

        if (! is_file($registro)) {
            return 0;
        }

        $lavorazione = $registro . '.' . now()->format('YmdHis');

        if (! @rename($registro, $lavorazione)) {
            $this->warn("  {$sito->domain}: non riesco a prendere in carico {$registro}.");

            return 0;
        }

        try {
            [$viste, $impronte, $lette] = $this->leggi($lavorazione, $sito);

            if ($this->option('secco')) {
                $this->line("  {$sito->domain}: {$lette} righe, "
                    . count($viste) . ' combinazioni, ' . count($impronte) . ' visitatori distinti (secco)');
                @rename($lavorazione, $registro);

                return 0;
            }

            $this->salva($sito, $viste, $impronte);
        } catch (\Throwable $e) {
            // Rimettere il file dov'era: un'importazione fallita non deve
            // portarsi via le visite che aveva preso in carico.
            @rename($lavorazione, $registro);

            throw $e;
        }

        @unlink($lavorazione);

        $this->line("  {$sito->domain}: {$lette} visite");

        return $lette;
    }

    /**
     * Legge il file e aggrega in memoria.
     *
     * @return array{0: array<string, array{giorno: string, categoria: string, agente: string, percorso: string, conteggio: int, con_js: int}>, 1: array<string, string>, 2: int}
     */
    private function leggi(string $file, Site $sito): array
    {
        $viste = [];
        $impronte = [];
        $lette = 0;

        $maniglia = fopen($file, 'r');

        if ($maniglia === false) {
            return [[], [], 0];
        }

        // Un sale per esecuzione, derivato dalla chiave dell'app e dal
        // giorno: non viene conservato da nessuna parte, quindi l'impronta
        // non e' ricostruibile a posteriori nemmeno da noi.
        $sale = hash('sha256', config('app.key') . ':viste:' . now()->toDateString());

        while (($riga = fgets($maniglia)) !== false) {
            $dato = json_decode(trim($riga), true);

            // Una riga troncata capita: il contatore scrive mentre noi
            // leggiamo il file precedente. Si salta, non si interrompe.
            if (! is_array($dato) || ! isset($dato['p'], $dato['q'])) {
                continue;
            }

            $giorno = substr((string) $dato['q'], 0, 10);

            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $giorno)) {
                continue;
            }

            $agente = (string) ($dato['u'] ?? '');
            $categoria = ClassificatoreAgente::categoria($agente);
            $nome = ClassificatoreAgente::nome($agente);
            $percorso = mb_substr((string) $dato['p'], 0, 300);

            $chiave = "{$giorno}|{$categoria}|{$nome}|{$percorso}";

            $viste[$chiave] ??= [
                'giorno' => $giorno,
                'categoria' => $categoria,
                'agente' => $nome,
                'percorso' => $percorso,
                'conteggio' => 0,
                'con_js' => 0,
            ];

            // Due eventi distinti: la visita ('v') e la conferma che quel
            // client esegue JavaScript ('j'). Una persona manda entrambi, e
            // contarli tutti e due come visite la raddoppierebbe.
            if (($dato['e'] ?? 'v') === 'j') {
                $viste[$chiave]['con_js']++;
            } else {
                $viste[$chiave]['conteggio']++;
            }

            // Solo le persone entrano nel conteggio dei visitatori distinti:
            // un crawler che passa da mille indirizzi diversi non e' mille
            // visitatori, e sommarlo renderebbe il numero inutile.
            if ($categoria === ClassificatoreAgente::UMANO
                && ($dato['e'] ?? 'v') !== 'j'
                && filled($dato['i'] ?? null)) {
                $impronta = substr(hash('sha256', $sale . '|' . $dato['i'] . '|' . $agente), 0, 32);
                $impronte["{$giorno}|{$impronta}"] = $giorno;
            }

            $lette++;
        }

        fclose($maniglia);

        return [$viste, $impronte, $lette];
    }

    /** @param array<string, array<string, mixed>> $viste */
    private function salva(Site $sito, array $viste, array $impronte): void
    {
        DB::transaction(function () use ($sito, $viste, $impronte): void {
            foreach ($viste as $v) {
                // I contatori si SOMMANO a quelli gia' presenti: la stessa
                // giornata viene importata piu' volte, una per passata del
                // cron. Una sostituzione perderebbe tutto il resto del
                // giorno.
                $riga = Vista::withoutSiteScope()->firstOrNew([
                    'site_id' => $sito->id,
                    'giorno' => $v['giorno'],
                    'categoria' => $v['categoria'],
                    'agente' => $v['agente'],
                    'percorso' => $v['percorso'],
                ]);

                $riga->site_id = $sito->id;
                $riga->conteggio = ($riga->conteggio ?? 0) + $v['conteggio'];
                $riga->con_js = ($riga->con_js ?? 0) + $v['con_js'];
                $riga->save();
            }

            foreach ($impronte as $chiave => $giorno) {
                [, $impronta] = explode('|', $chiave, 2);

                // firstOrNew e non firstOrCreate: `site_id` non e' fillable
                // — di proposito, in un sistema multitenant e' l'ultima
                // colonna che si vuole assegnabile in massa — quindi
                // `firstOrCreate` la scarterebbe e il modello fallirebbe
                // con "nessun sito corrente nel contesto" (regola 2 di
                // CLAUDE.md). Va valorizzata a mano.
                $riga = VistaImpronta::withoutSiteScope()->firstOrNew([
                    'site_id' => $sito->id,
                    'giorno' => $giorno,
                    'impronta' => $impronta,
                ]);

                if (! $riga->exists) {
                    $riga->site_id = $sito->id;
                    $riga->save();
                }
            }
        });
    }

    public function percorsoRegistro(Site $sito): string
    {
        return str_replace('{dominio}', $sito->domain, (string) config('slimcms.registro_viste'));
    }
}
