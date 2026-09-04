<?php

namespace App\Services;

use App\Models\Site;
use Carbon\CarbonImmutable;

/**
 * Controlla DNS e certificato di un dominio.
 *
 * Nessuna scrittura di configurazione: qui si OSSERVA soltanto. Il
 * provisioning vero richiede privilegi di root (vedi ProvisionaDominio) e
 * questo separa nettamente cio' che si puo' fare in automatico e in
 * sicurezza da cio' che richiede un intervento.
 */
class StatoDominio
{
    /** Giorni sotto i quali un certificato va considerato in scadenza. */
    public const SOGLIA_SCADENZA = 21;

    public function __construct(
        private readonly ?string $ipAtteso = null,
    ) {}

    private function ipAtteso(): ?string
    {
        return $this->ipAtteso ?? config('slimcms.ip_server');
    }

    /**
     * Il dominio punta a questo server?
     *
     * @return array{stato: string, dettaglio: string}
     */
    public function verificaDns(string $dominio): array
    {
        $atteso = $this->ipAtteso();
        $record = @dns_get_record($dominio, DNS_A);

        if ($record === false || $record === []) {
            // Distinguere "non risolve" da "punta altrove" e' importante: il
            // primo e' un DNS non ancora propagato, il secondo un errore di
            // configurazione del cliente. Le azioni sono diverse.
            return ['stato' => 'non_risolve', 'dettaglio' => 'Nessun record A per ' . $dominio];
        }

        $ip = collect($record)->pluck('ip')->filter()->values();

        if ($atteso === null) {
            return ['stato' => 'sconosciuto', 'dettaglio' => 'IP del server non configurato (slimcms.ip_server)'];
        }

        if ($ip->contains($atteso)) {
            return ['stato' => 'ok', 'dettaglio' => $ip->implode(', ')];
        }

        return [
            'stato' => 'punta_altrove',
            'dettaglio' => 'Risolve a ' . $ip->implode(', ') . ' invece che a ' . $atteso,
        ];
    }

    /**
     * Stato del certificato TLS servito dal dominio.
     *
     * @return array{stato: string, scadenza: ?CarbonImmutable, emittente: ?string, dettaglio: string}
     */
    public function verificaCertificato(string $dominio): array
    {
        $contesto = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                // Non verifichiamo la catena: vogliamo ISPEZIONARE anche un
                // certificato scaduto o sbagliato, non rifiutarlo. Il giudizio
                // lo diamo noi qui sotto, guardando le date.
                'verify_peer' => false,
                'verify_peer_name' => false,
                'SNI_enabled' => true,
                'peer_name' => $dominio,
            ],
        ]);

        $conn = @stream_socket_client(
            'ssl://' . $dominio . ':443',
            $errno,
            $errstr,
            8,
            STREAM_CLIENT_CONNECT,
            $contesto
        );

        if ($conn === false) {
            return [
                'stato' => 'irraggiungibile',
                'scadenza' => null,
                'emittente' => null,
                'dettaglio' => trim($errstr) ?: 'connessione TLS fallita',
            ];
        }

        $params = stream_context_get_params($conn);
        fclose($conn);

        $cert = @openssl_x509_parse($params['options']['ssl']['peer_certificate'] ?? null);

        if ($cert === false || $cert === null) {
            return ['stato' => 'illeggibile', 'scadenza' => null, 'emittente' => null, 'dettaglio' => 'certificato non interpretabile'];
        }

        $scadenza = CarbonImmutable::createFromTimestampUTC($cert['validTo_time_t']);
        $emittente = $cert['issuer']['CN'] ?? ($cert['issuer']['O'] ?? null);

        $giorni = (int) now()->diffInDays($scadenza, false);

        $stato = match (true) {
            $giorni < 0 => 'scaduto',
            $giorni <= self::SOGLIA_SCADENZA => 'in_scadenza',
            default => 'valido',
        };

        return [
            'stato' => $stato,
            'scadenza' => $scadenza,
            'emittente' => $emittente,
            'dettaglio' => $giorni < 0
                ? 'scaduto da ' . abs($giorni) . ' giorni'
                : 'scade fra ' . $giorni . ' giorni',
        ];
    }

    /** Aggiorna lo stato salvato su un sito. */
    public function aggiorna(Site $site): array
    {
        $dns = $this->verificaDns($site->domain);
        $cert = $this->verificaCertificato($site->domain);

        $site->forceFill([
            'dns_status' => $dns['stato'],
            'ssl_status' => $cert['stato'],
            'ssl_expires_at' => $cert['scadenza'],
            'ssl_checked_at' => now(),
            'ssl_last_error' => in_array($cert['stato'], ['valido', 'in_scadenza'], true)
                ? null
                : $cert['dettaglio'],
        ])->saveQuietly(); // saveQuietly: un controllo di stato non deve accodare una build

        return ['dns' => $dns, 'cert' => $cert];
    }
}
