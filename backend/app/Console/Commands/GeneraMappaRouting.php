<?php

namespace App\Console\Commands;

use App\Services\MappaRouting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Rigenera la mappa dominio -> sito per l'edge (specifiche 7.2).
 *
 * Va lanciata solo su eventi strutturali (sito creato, dominio cambiato), non
 * a ogni pubblicazione: e' proprio il punto della sezione 7.2, che la
 * risoluzione del dominio non stia nel percorso di lettura.
 * SiteObserver la richiama da solo quando serve.
 */
class GeneraMappaRouting extends Command
{
    protected $signature = 'slimcms:mappa-routing
                            {--formato=entrambi : json, nginx o entrambi}
                            {--stdout : stampa invece di scrivere i file}';

    protected $description = 'Rigenera la mappa dominio -> sito per l\'edge';

    public function handle(MappaRouting $mappa): int
    {
        $formato = $this->option('formato');
        $destinazione = storage_path('app/routing');

        $uscite = [];

        if (in_array($formato, ['json', 'entrambi'], true)) {
            $uscite['routing.json'] = $mappa->json();
        }

        if (in_array($formato, ['nginx', 'entrambi'], true)) {
            $uscite['slimcms-map.conf'] = $mappa->nginx();
        }

        if ($uscite === []) {
            $this->error('Formato non valido: usa json, nginx o entrambi.');

            return self::FAILURE;
        }

        if ($this->option('stdout')) {
            foreach ($uscite as $contenuto) {
                $this->line($contenuto);
            }

            return self::SUCCESS;
        }

        File::ensureDirectoryExists($destinazione);

        foreach ($uscite as $nome => $contenuto) {
            // Scrittura atomica: l'edge potrebbe leggere il file mentre lo
            // stiamo scrivendo, e una mappa troncata manderebbe i visitatori
            // sul sito sbagliato o su nessuno.
            $tmp = $destinazione . '/.' . $nome . '.tmp';
            File::put($tmp, $contenuto);
            File::move($tmp, $destinazione . '/' . $nome);

            $this->line('  ' . $destinazione . '/' . $nome . '  (' . strlen($contenuto) . ' byte)');
        }

        $this->info(count($mappa->voci()) . ' domini nella mappa.');

        return self::SUCCESS;
    }
}
