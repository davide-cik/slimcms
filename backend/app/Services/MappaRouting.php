<?php

namespace App\Services;

use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * Genera la mappa dominio -> sito consumata dall'edge (specifiche 7.2).
 *
 * Il punto della sezione 7.2 e' che la risoluzione del tenant NON avvenga a
 * runtime a ogni richiesta: la mappa si rigenera solo su eventi strutturali
 * (sito creato, dominio cambiato), e da li' in poi l'edge serve i file senza
 * mai interpellare Laravel.
 *
 * Due formati, perche' due edge diversi:
 *  - json:  Cloudflare Workers + KV, o qualunque edge programmabile
 *  - nginx: mappa per il server che serve oggi i siti su questa macchina
 */
class MappaRouting
{
    /** @return Collection<int, array<string, mixed>> */
    public function voci(): Collection
    {
        return Site::withoutTenancy()
            ->with('tenant')
            ->orderBy('domain')
            ->get()
            ->map(fn (Site $s) => [
                'domain' => $s->domain,
                'site_id' => $s->id,
                'tenant_id' => $s->tenant_id,
                'root' => $this->documentRoot($s),
                // Un sito di un cliente sospeso resta servito: staccare il
                // sito e' una decisione commerciale, non una conseguenza
                // automatica di un mancato pagamento. L'edge sa lo stato e
                // puo' decidere, ma non lo decidiamo qui.
                'tenant_status' => $s->tenant?->status ?? 'sconosciuto',
            ]);
    }

    public function json(): string
    {
        return json_encode([
            'generata_il' => now()->toIso8601String(),
            'voci' => $this->voci()->values(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Mappa nginx: dominio -> document root.
     *
     * Va inclusa in un blocco http{} e usata con root $slimcms_root; in un
     * server{} con server_name catch-all. Il default vuoto e' voluto: un
     * dominio sconosciuto non deve finire per sbaglio sul sito di un altro
     * cliente, deve dare errore.
     */
    /**
     * Mappa nginx: dominio -> document root.
     *
     * Va inclusa in un blocco http{} e usata con `root $slimcms_root;` in un
     * server{} con server_name catch-all.
     */
    public function nginx(): string
    {
        $righe = $this->voci()
            ->map(fn (array $v) => sprintf('    %-42s %s;', $v['domain'], $v['root']))
            ->implode("\n");

        $data = now()->format('d/m/Y H:i');

        return <<<CONF
            # Mappa dominio -> document root, generata da SlimCMS il {$data}.
            # NON modificare a mano: rigenerata da `php artisan slimcms:mappa-routing`.

            map \$host \$slimcms_root {
                # Il default vuoto e' voluto: un dominio sconosciuto deve dare
                # errore, non finire per sbaglio sul sito di un altro cliente.
                default "";

            {$righe}
            }

            CONF;
    }

    private function documentRoot(Site $site): string
    {
        return sprintf('/home/%s/web/%s/public_html', config('slimcms.utente_hosting'), $site->domain);
    }
}
