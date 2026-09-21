<?php

namespace App\Http\Resources\Concerns;

/**
 * Blocchi del page builder pronti per Astro: immagini e moduli risolti.
 *
 * Condiviso da pagine e prodotti, che usano lo stesso builder e la stessa
 * collezione `immagini`. Due copie divergerebbero alla prima modifica, e il
 * sintomo sarebbe una galleria vuota su un tipo di contenuto solo.
 * Richiede ConMedia.
 */
trait RisolveBlocchi
{
    /**
     * Sostituisce nei blocchi gli uuid dei media con la loro forma pubblica.
     *
     * I blocchi salvano un riferimento, non il file: la stessa immagine puo'
     * comparire in piu' blocchi, e l'alt vive sul file. Risolvere qui evita
     * che ogni consumatore debba incrociare `blocks` con `media` da solo,
     * sbagliando in modo diverso ogni volta.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function blocchiRisolti(): array
    {
        $perUuid = $this->getMedia('immagini')->keyBy('uuid');

        $risolvi = function ($valore) use (&$risolvi, $perUuid) {
            if (is_string($valore)) {
                return $perUuid->has($valore)
                    ? $this->mediaPubblico($perUuid->get($valore))
                    : $valore;
            }

            if (is_array($valore)) {
                return array_map($risolvi, $valore);
            }

            return $valore;
        };

        return array_map(
            function (array $blocco) use ($risolvi): array {
                $dati = $risolvi($blocco['data'] ?? []);

                // Un blocco modulo porta l'id del modulo; il sito ha bisogno
                // dei suoi campi per disegnarlo. Si risolvono qui come le
                // immagini, per la stessa ragione: Astro riceve tutto cio'
                // che gli serve e non fa una seconda domanda all'API.
                if (($blocco['type'] ?? null) === 'modulo_contatto') {
                    $dati['modulo'] = $this->moduloRisolto($dati['modulo_id'] ?? null);
                    unset($dati['modulo_id']);
                }

                return ['type' => $blocco['type'] ?? null, 'data' => $dati];
            },
            array_values($this->blocks ?? [])
        );
    }

    /**
     * Il modulo di un blocco, con i suoi campi.
     *
     * Se non c'e' o non e' attivo si ricade su quello di contatto del sito, e
     * se non esiste nemmeno quello si torna `null`: il sito disegna comunque
     * i tre campi di sempre, invece di mostrare un modulo vuoto o di far
     * fallire la build per una configurazione incompleta.
     *
     * @return array<string, mixed>|null
     */
    protected function moduloRisolto(int|string|null $id): ?array
    {
        $modulo = filled($id)
            ? \App\Models\Modulo::query()->attivi()->find($id)
            : \App\Models\Modulo::query()->attivi()->orderBy('id')->first();

        if ($modulo === null) {
            return null;
        }

        return [
            'slug' => $modulo->slug,
            'nome' => $modulo->nome,
            'conferma' => $modulo->messaggio_conferma,
            'campi' => $modulo->campiNormalizzati(),
        ];
    }
}
