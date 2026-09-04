<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Converte i blocchi al formato del Builder di Filament.
 *
 * I contenuti erano salvati piatti, con il tipo in una chiave "tipo":
 *   ['tipo' => 'hero', 'titolo' => '...', 'testo' => '...']
 *
 * Il Builder di Filament vuole invece tipo e dati separati:
 *   ['type' => 'hero', 'data' => ['titolo' => '...', 'testo' => '...']]
 *
 * Con la forma vecchia il builder non riconosceva nulla e mostrava la pagina
 * SENZA BLOCCHI: chi avesse salvato da li' avrebbe cancellato il contenuto
 * senza accorgersene. E' il motivo per cui questa migrazione esiste invece di
 * lasciar convivere i due formati.
 *
 * Idempotente: le righe gia' convertite vengono lasciate stare.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['pages', 'posts'] as $tabella) {
            $this->converti($tabella);
        }
    }

    public function down(): void
    {
        foreach (['pages', 'posts'] as $tabella) {
            $this->torna($tabella);
        }
    }

    private function converti(string $tabella): void
    {
        DB::table($tabella)->whereNotNull('blocks')->orderBy('id')
            ->each(function (object $riga) use ($tabella) {
                $blocchi = json_decode((string) $riga->blocks, true);

                if (! is_array($blocchi) || $blocchi === []) {
                    return;
                }

                $nuovi = array_map(function (array $b): array {
                    // Gia' nel formato giusto: non toccare.
                    if (array_key_exists('type', $b) && array_key_exists('data', $b)) {
                        return $b;
                    }

                    $tipo = $b['tipo'] ?? $b['type'] ?? null;
                    unset($b['tipo'], $b['type']);

                    return ['type' => $tipo, 'data' => $b];
                }, $blocchi);

                DB::table($tabella)->where('id', $riga->id)
                    ->update(['blocks' => json_encode($nuovi, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            });
    }

    private function torna(string $tabella): void
    {
        DB::table($tabella)->whereNotNull('blocks')->orderBy('id')
            ->each(function (object $riga) use ($tabella) {
                $blocchi = json_decode((string) $riga->blocks, true);

                if (! is_array($blocchi) || $blocchi === []) {
                    return;
                }

                $vecchi = array_map(
                    fn (array $b): array => array_key_exists('type', $b)
                        ? ['tipo' => $b['type']] + ($b['data'] ?? [])
                        : $b,
                    $blocchi
                );

                DB::table($tabella)->where('id', $riga->id)
                    ->update(['blocks' => json_encode($vecchi, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            });
    }
};
