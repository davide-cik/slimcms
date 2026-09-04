<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\StatoDominio;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Prepara un dominio custom: vhost + certificato Let's Encrypt.
 *
 * SU QUESTA MACCHINA i vhost li gestisce HestiaCP, e la sua CLI richiede
 * root: `v-list-web-domains` da utente normale risponde "Permission denied".
 * Il comando quindi verifica tutto cio' che puo' verificare, e se non ha i
 * privilegi STAMPA i comandi esatti da eseguire invece di fallire in modo
 * opaco. Chi ha sudo li incolla; con privilegi, li esegue da solo.
 *
 * L'ordine non e' negoziabile: Let's Encrypt valida via HTTP, quindi il DNS
 * deve gia' puntare qui PRIMA di chiedere il certificato. Chiederlo prima
 * consuma un tentativo verso i limiti di emissione di Let's Encrypt, che sono
 * stretti e si esauriscono in fretta se si insiste.
 */
class ProvisionaDominio extends Command
{
    protected $signature = 'slimcms:provisiona-dominio
                            {dominio : il dominio da configurare}
                            {--forza : procedi anche se il DNS non punta ancora qui}';

    protected $description = 'Configura vhost e certificato per un dominio custom';

    public function handle(StatoDominio $stato): int
    {
        $dominio = mb_strtolower(trim($this->argument('dominio')));

        $site = Site::withoutTenancy()->where('domain', $dominio)->first();

        if ($site === null) {
            $this->error("Nessun sito con dominio {$dominio}. Crealo prima nel control plane.");

            return self::FAILURE;
        }

        $this->line("Sito: {$site->name} (cliente {$site->tenant_id})");
        $this->newLine();

        // 1. DNS
        $this->line('1. Controllo DNS');
        $dns = $stato->verificaDns($dominio);

        if ($dns['stato'] !== 'ok') {
            $this->line('   <fg=red>' . $dns['stato'] . '</> — ' . $dns['dettaglio']);
            $this->newLine();
            $this->istruzioniDns($dominio);
            $this->newLine();

            if (! $this->option('forza')) {
                $this->warn('Interrotto. Let\'s Encrypt valida via HTTP: senza DNS corretto');
                $this->warn('il tentativo fallisce e consuma quota verso i limiti di emissione.');
                $this->line('Usa --forza solo se sai che il DNS sta propagando adesso.');

                return self::FAILURE;
            }
        } else {
            $this->line('   <fg=green>ok</> — punta a ' . $dns['dettaglio']);
        }

        // 2. Vhost e certificato
        $utente = config('slimcms.utente_hosting');
        $ip = config('slimcms.ip_server');

        // L'IP va SEMPRE passato esplicitamente. Senza, Hestia usa l'IP di
        // default dell'utente, che su questo server e' un indirizzo interno
        // (10.0.0.2): il vhost nasce in ascolto li', le richieste da internet
        // non lo incontrano mai e cadono sul vhost di default. Il sintomo e'
        // fuorviante, perche' Let's Encrypt fallisce con un 404 sul percorso
        // di validazione e sembra un problema di ACME.
        $comandi = [
            "v-add-domain {$utente} {$dominio} {$ip}",
            "v-add-letsencrypt-domain {$utente} {$dominio}",
        ];

        $this->newLine();
        $this->line('2. Vhost e certificato');

        if (! $this->haPrivilegi()) {
            $this->line('   <fg=yellow>privilegi insufficienti</> — la CLI di HestiaCP richiede root.');
            $this->newLine();
            $this->line('   Esegui:');

            foreach ($comandi as $c) {
                $this->line("     sudo /usr/local/hestia/bin/{$c}");
            }

            $this->newLine();
            $this->line('   Poi rilancia questo comando per verificare il risultato.');

            $site->forceFill(['ssl_status' => 'da_configurare'])->saveQuietly();

            return self::FAILURE;
        }

        foreach ($comandi as $c) {
            $this->line("   eseguo: {$c}");
            $r = Process::timeout(180)->run('/usr/local/hestia/bin/' . $c);

            if (! $r->successful()) {
                $this->error('   fallito: ' . trim($r->errorOutput() ?: $r->output()));
                $site->forceFill([
                    'ssl_status' => 'fallito',
                    'ssl_last_error' => mb_substr(trim($r->errorOutput() ?: $r->output()), 0, 1000),
                ])->saveQuietly();

                return self::FAILURE;
            }
        }

        // 3. Verifica
        $this->newLine();
        $this->line('3. Verifica del certificato emesso');
        $r = $stato->aggiorna($site);

        $this->line('   ' . $r['cert']['stato'] . ' — ' . $r['cert']['dettaglio']);

        return in_array($r['cert']['stato'], ['valido', 'in_scadenza'], true)
            ? self::SUCCESS
            : self::FAILURE;
    }


    /**
     * Istruzioni DNS da dare al cliente.
     *
     * Distingue apice e sottodominio perche' la differenza non e' un
     * dettaglio: un CNAME non puo' stare all'apice di una zona (RFC 1034), e
     * dare l'istruzione sbagliata fa perdere un giro di email col cliente.
     */
    private function istruzioniDns(string $dominio): void
    {
        $target = config('slimcms.cname_target');
        $ip = config('slimcms.ip_server');
        $apice = substr_count($dominio, '.') === 1;

        $this->line('   Il cliente deve configurare il DNS cosi:');
        $this->newLine();

        if ($apice) {
            $this->line("     {$dominio}.        A      {$ip}");
            $this->line("     www.{$dominio}.    CNAME  {$target}.");
            $this->newLine();
            $this->line("   Nota: {$dominio} e' un dominio all'apice e non puo' essere un CNAME");
            $this->line('   (RFC 1034). Se il suo DNS supporta ALIAS/ANAME o CNAME flattening');
            $this->line("   (Cloudflare lo fa), puo' usare {$target} anche all'apice.");
        } else {
            $this->line("     {$dominio}.  CNAME  {$target}.");
        }
    }

    /** La CLI di Hestia e' leggibile solo da root: se non lo siamo, si vede subito. */
    private function haPrivilegi(): bool
    {
        return Process::run('/usr/local/hestia/bin/v-list-users json')->successful()
            && ! str_contains(Process::run('/usr/local/hestia/bin/v-list-users json')->errorOutput(), 'Permission denied');
    }
}
