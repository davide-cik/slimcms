<?php

namespace App\Services;

use App\Models\BuildRequest;
use App\Models\Site;
use Illuminate\Support\Facades\DB;

/**
 * Accoda le rigenerazioni statiche, con debounce.
 *
 * Il debounce (specifiche 7.1) esiste perche' un redattore che salva cinque
 * volte in due minuti non deve produrre cinque build: la prima richiesta apre
 * una finestra, le successive vi confluiscono spostandone la scadenza in
 * avanti solo se necessario.
 */
class BuildQueue
{
    /** Secondi di attesa prima che una build accodata parta davvero. */
    public const DEBOUNCE = 45;

    /** Attesa massima: oltre questa una build parte comunque, anche se
     *  continuano ad arrivare modifiche. Senza questo tetto un redattore che
     *  salva di continuo terrebbe la build ferma per sempre. */
    public const ATTESA_MASSIMA = 300;

    public static function accoda(
        Site $site,
        string $reason,
        string $scope = 'incremental',
        ?array $paths = null,
    ): BuildRequest {
        return DB::transaction(function () use ($site, $reason, $scope, $paths) {
            // lockForUpdate: due salvataggi quasi simultanei non devono creare
            // due richieste in attesa per lo stesso sito.
            $inAttesa = BuildRequest::query()
                ->where('site_id', $site->id)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();

            if ($inAttesa !== null) {
                // Una build completa assorbe le incrementali: se e' gia'
                // previsto di rigenerare tutto, i singoli path non aggiungono
                // niente.
                if ($scope === 'full') {
                    $inAttesa->scope = 'full';
                    $inAttesa->paths = null;
                } elseif ($inAttesa->scope !== 'full') {
                    $inAttesa->paths = array_values(array_unique(
                        array_merge($inAttesa->paths ?? [], $paths ?? [])
                    ));
                }

                $nuovaScadenza = now()->addSeconds(self::DEBOUNCE);
                $tetto = $inAttesa->created_at->addSeconds(self::ATTESA_MASSIMA);

                $inAttesa->run_after = $nuovaScadenza->min($tetto);
                $inAttesa->reason = $reason;
                $inAttesa->save();

                return $inAttesa;
            }

            return BuildRequest::create([
                'site_id' => $site->id,
                'reason' => $reason,
                'scope' => $scope,
                'paths' => $scope === 'full' ? null : $paths,
                'status' => 'pending',
                // Il primo deploy di un sito nuovo non aspetta: le specifiche
                // chiedono tempi di attivazione rapidi per i nuovi clienti.
                'run_after' => $reason === 'site.created' ? now() : now()->addSeconds(self::DEBOUNCE),
            ]);
        });
    }
}
