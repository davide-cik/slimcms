<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\StatoDominio;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Controlla DNS e certificato di tutti i siti, e avvisa se qualcosa non va.
 *
 * Le specifiche (sezione 11) chiedono esattamente questo: "il rinnovo
 * automatico va monitorato con un alert se un certificato non si rinnova
 * correttamente, per non ritrovarsi un sito cliente offline per certificato
 * scaduto".
 *
 * Il rinnovo in se' lo fa HestiaCP col suo cron di Let's Encrypt: qui si
 * verifica che l'abbia FATTO. E' la differenza fra avere il rinnovo automatico
 * e sapere che sta funzionando.
 */
class MonitoraCertificati extends Command
{
    protected $signature = 'slimcms:monitora-certificati
                            {--silenzioso : non stampa i siti a posto}';

    protected $description = 'Verifica DNS e certificati di tutti i siti, con alert sulle scadenze';

    public function handle(StatoDominio $stato): int
    {
        $siti = Site::withoutTenancy()
            ->orderBy('domain')
            ->get()
            // I TLD riservati (RFC 2606 e RFC 6761) non possono esistere in
            // rete: sono i siti demo e di sviluppo. Controllarli produrrebbe
            // un alert al giorno che nessuno leggerebbe piu', e un alert che
            // si impara a ignorare e' peggio di nessun alert.
            ->reject(fn (Site $s) => (bool) preg_match('/\.(test|local|localhost|invalid|example)$/i', $s->domain));

        if ($siti->isEmpty()) {
            $this->info('Nessun sito da controllare.');

            return self::SUCCESS;
        }

        $problemi = [];

        foreach ($siti as $site) {
            $r = $stato->aggiorna($site);
            $cert = $r['cert'];
            $dns = $r['dns'];

            $grave = ! in_array($cert['stato'], ['valido'], true)
                || ! in_array($dns['stato'], ['ok'], true);

            if (! $grave) {
                if (! $this->option('silenzioso')) {
                    $this->line(sprintf(
                        '  <fg=green>OK</>   %-34s %s',
                        $site->domain,
                        $cert['dettaglio']
                    ));
                }

                continue;
            }

            $riga = sprintf(
                '%-34s DNS: %-14s TLS: %-14s %s',
                $site->domain,
                $dns['stato'],
                $cert['stato'],
                $cert['dettaglio']
            );

            $problemi[] = $riga;
            $this->line('  <fg=red>ATTENZIONE</> ' . $riga);
        }

        if ($problemi === []) {
            $this->info('Tutti i certificati sono validi.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->error(count($problemi) . ' siti con problemi di dominio o certificato.');

        $this->avvisa($problemi);

        // Exit code diverso da zero: cosi' il cron lo segnala anche senza
        // leggere il log, e un eventuale sistema di monitoring lo intercetta.
        return self::FAILURE;
    }

    /** @param array<string> $problemi */
    private function avvisa(array $problemi): void
    {
        $destinatario = config('slimcms.email_alert');

        if (blank($destinatario)) {
            return;
        }

        $corpo = "Siti con problemi di dominio o certificato:\n\n"
            . implode("\n", $problemi)
            . "\n\nControllo eseguito il " . now()->format('d/m/Y H:i') . ".";

        try {
            Mail::raw($corpo, function ($m) use ($destinatario) {
                $m->to($destinatario)->subject('[SlimCMS] Certificati da controllare');
            });

            $this->line('Alert inviato a ' . $destinatario . '.');
        } catch (\Throwable $e) {
            // Un alert che fallisce non deve far fallire il controllo: il
            // problema principale e' gia' stato segnalato a schermo e nel log.
            $this->warn('Alert non inviato: ' . $e->getMessage());
        }
    }
}
