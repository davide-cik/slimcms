<?php

namespace App\Console\Commands;

use App\Models\Media;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Trova i file rimasti sul disco senza una riga corrispondente nel database.
 *
 * Succede quando il database viene azzerato (la suite di test usa
 * RefreshDatabase) o quando una cancellazione fallisce a meta'. I file orfani
 * non fanno danno, ma occupano spazio che viene conteggiato nel piano del
 * cliente, quindi vanno visti.
 *
 * Di default REPORTA soltanto: cancellare file e' irreversibile e va chiesto
 * esplicitamente con --elimina.
 */
class PulisciMediaOrfani extends Command
{
    protected $signature = 'slimcms:media-orfani {--elimina : cancella davvero i file, invece di elencarli soltanto}';

    protected $description = 'Elenca (o cancella) i file media senza riga nel database';

    public function handle(): int
    {
        $disco = Storage::disk(config('media-library.disk_name'));

        if (! $disco->exists('tenants')) {
            $this->info('Nessuna cartella media sul disco.');

            return self::SUCCESS;
        }

        // withoutSiteScope: qui serve la vista globale, e' manutenzione di
        // piattaforma. Le cartelle sono numerate con l'id del media.
        $idValidi = Media::withoutSiteScope()->pluck('id')->flip();

        $orfani = [];
        $byte = 0;

        foreach ($disco->directories('tenants') as $cartellaTenant) {
            foreach ($disco->directories($cartellaTenant . '/media') as $cartellaMedia) {
                $id = (int) basename($cartellaMedia);

                if ($idValidi->has($id)) {
                    continue;
                }

                $peso = collect($disco->allFiles($cartellaMedia))->sum(fn ($f) => $disco->size($f));
                $orfani[] = [$cartellaMedia, $peso];
                $byte += $peso;
            }
        }

        if ($orfani === []) {
            $this->info('Nessun file orfano.');

            return self::SUCCESS;
        }

        $this->warn(count($orfani) . ' cartelle orfane, ' . round($byte / 1048576, 2) . ' MB:');

        foreach ($orfani as [$cartella, $peso]) {
            $this->line('  ' . $cartella . '  (' . round($peso / 1024) . ' KB)');
        }

        if (! $this->option('elimina')) {
            $this->newLine();
            $this->line('Nessun file cancellato. Rilancia con --elimina per rimuoverli.');

            return self::SUCCESS;
        }

        foreach ($orfani as [$cartella]) {
            $disco->deleteDirectory($cartella);
        }

        $this->info('Rimosse ' . count($orfani) . ' cartelle.');

        return self::SUCCESS;
    }
}
