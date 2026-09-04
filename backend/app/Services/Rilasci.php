<?php

namespace App\Services;

use Illuminate\Support\Collection;

/**
 * Versione dell'applicazione e storico dei rilasci.
 *
 * La versione NON e' un file che si aggiorna a mano: si deriva dalla posizione
 * del commit nella storia, quindi l'N-esimo commit e' 0.0.N. Cosi' versione e
 * commit non possono andare fuori sincrono, che e' il problema di ogni
 * VERSION scritto a mano.
 *
 * In produzione l'app e' una copia senza .git, quindi l'elenco viene generato
 * al deploy in rilasci.json e letto da li'. In sviluppo si legge git
 * direttamente, cosi' la pagina mostra sempre lo stato reale mentre si lavora.
 */
class Rilasci
{
    public const PER_PAGINA = 50;

    /** @var Collection<int, array<string, mixed>>|null */
    private ?Collection $cache = null;

    public function versione(): string
    {
        return $this->tutti()->first()['versione'] ?? '0.0.0';
    }

    /** Il commit corrispondente alla versione in esecuzione. */
    public function commitCorrente(): ?string
    {
        return $this->tutti()->first()['hash'] ?? null;
    }

    /** @return Collection<int, array<string, mixed>> */
    public function tutti(): Collection
    {
        return $this->cache ??= $this->daFile() ?? $this->daGit();
    }

    /** @return Collection<int, array<string, mixed>> */
    public function pagina(int $pagina = 1): Collection
    {
        return $this->tutti()->slice(($pagina - 1) * self::PER_PAGINA, self::PER_PAGINA)->values();
    }

    public function pagineTotali(): int
    {
        return max(1, (int) ceil($this->tutti()->count() / self::PER_PAGINA));
    }

    public function percorsoFile(): string
    {
        return base_path('rilasci.json');
    }

    /** @return Collection<int, array<string, mixed>>|null */
    private function daFile(): ?Collection
    {
        $file = $this->percorsoFile();

        if (! is_readable($file)) {
            return null;
        }

        $dati = json_decode((string) file_get_contents($file), true);

        return is_array($dati) ? collect($dati) : null;
    }

    /**
     * Legge git. Usato solo in sviluppo: in produzione .git non c'e'.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function daGit(): Collection
    {
        $radice = base_path('..');

        if (! is_dir($radice . '/.git')) {
            return collect();
        }

        return static::daOutputGit($this->eseguiGit($radice));
    }

    private function eseguiGit(string $radice): string
    {
        // %x1f separatore di campo, %x1e di record: caratteri di controllo che
        // non possono comparire in un messaggio di commit, a differenza di
        // qualunque separatore stampabile.
        $comando = sprintf(
            'git -C %s log --pretty=format:%%H%%x1f%%an%%x1f%%aI%%x1f%%s%%x1f%%b%%x1e 2>/dev/null',
            escapeshellarg($radice)
        );

        return (string) @shell_exec($comando);
    }

    /**
     * Trasforma l'output di git in rilasci numerati.
     *
     * Il piu' VECCHIO commit e' 0.0.1: la numerazione parte dal fondo, cosi'
     * un commit non cambia mai numero quando ne arrivano di nuovi.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function daOutputGit(string $output): Collection
    {
        $record = collect(explode("\x1e", $output))
            ->map(fn (string $r) => trim($r, "\n"))
            ->filter(fn (string $r) => $r !== '')
            ->values();

        $totale = $record->count();

        return $record->map(function (string $r, int $i) use ($totale) {
            [$hash, $autore, $data, $oggetto, $corpo] = array_pad(explode("\x1f", $r), 5, '');

            return [
                'versione' => '0.0.' . ($totale - $i),
                'hash' => $hash,
                'hash_breve' => mb_substr($hash, 0, 7),
                'autore' => $autore,
                'data' => $data,
                'titolo' => $oggetto,
                'corpo' => trim($corpo),
            ];
        });
    }
}
