<?php

namespace App\Console\Commands;

use App\Services\Rilasci;
use Illuminate\Console\Command;

/**
 * Scrive lo storico dei rilasci in un file JSON.
 *
 * Serve perche' in produzione l'applicazione e' una copia senza .git: senza
 * questo file la pagina /rilasci sarebbe vuota e la versione mostrata sarebbe
 * 0.0.0, cioe' peggio che non mostrarla. Va eseguito dal repository, dove git
 * c'e', scrivendo nella destinazione del deploy.
 */
class GeneraRilasci extends Command
{
    protected $signature = 'slimcms:genera-rilasci {--out= : file di destinazione}';

    protected $description = 'Genera rilasci.json dallo storico git';

    public function handle(Rilasci $rilasci): int
    {
        $elenco = $rilasci->tutti();

        if ($elenco->isEmpty()) {
            $this->error('Nessun rilascio trovato: git non e leggibile da qui?');

            return self::FAILURE;
        }

        $destinazione = $this->option('out') ?: $rilasci->percorsoFile();

        // Scrittura atomica: la pagina potrebbe leggere il file mentre lo
        // stiamo scrivendo, e un JSON troncato la manderebbe in errore.
        $tmp = $destinazione . '.tmp';
        file_put_contents($tmp, $elenco->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        rename($tmp, $destinazione);

        $this->info($elenco->count() . ' rilasci scritti in ' . $destinazione);
        $this->line('  versione corrente: ' . $rilasci->versione());

        return self::SUCCESS;
    }
}
