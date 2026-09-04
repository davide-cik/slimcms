<?php

namespace App\Console\Commands;

use App\Models\BuildRequest;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/**
 * Esegue le build in attesa la cui finestra di debounce e' scaduta.
 *
 * PERCHE' NON E' UN WORKER: le specifiche (7.1) prevedono Laravel Queue con
 * Horizon, ma su questa macchina php.ini disabilita pcntl_*, quindi
 * `queue:work` in modalita' daemon e Horizon non partono affatto
 * ("Call to undefined function pcntl_signal"). Questo comando fa lo stesso
 * lavoro in modo idempotente ed e' pensato per essere chiamato da cron ogni
 * minuto, come schedule-run.sh su zuzai.
 *
 * Se un giorno pcntl venisse abilitato, la migrazione a un job vero e' un
 * refactor locale: la logica di debounce sta gia' in BuildQueue.
 */
class RunBuildQueue extends Command
{
    protected $signature = 'slimcms:build-queue
                            {--max=3 : quante build eseguire al massimo in questa passata}
                            {--site= : forza la build di un dominio specifico, ignorando il debounce}';

    protected $description = 'Esegue le rigenerazioni statiche in attesa';

    private const TENTATIVI_MASSIMI = 3;

    public function handle(): int
    {
        if ($dominio = $this->option('site')) {
            return $this->forza($dominio);
        }

        $eseguite = 0;

        for ($i = 0; $i < (int) $this->option('max'); $i++) {
            $richiesta = $this->prendiProssima();

            if ($richiesta === null) {
                break;
            }

            $this->esegui($richiesta);
            $eseguite++;
        }

        if ($eseguite === 0) {
            $this->line('Nessuna build in attesa.');
        }

        // Le specifiche chiedono un alert se una build resta in coda oltre
        // una soglia: qui viene almeno segnalato in modo visibile nei log.
        $inRitardo = BuildRequest::inRitardo(5)->count();

        if ($inRitardo > 0) {
            $this->warn("ATTENZIONE: {$inRitardo} build ferme in coda da oltre 5 minuti.");
        }

        return self::SUCCESS;
    }

    /**
     * Prende una richiesta e la marca "running" dentro una transazione, cosi'
     * due esecuzioni sovrapposte del cron non lavorano sulla stessa build.
     */
    private function prendiProssima(): ?BuildRequest
    {
        return DB::transaction(function () {
            $r = BuildRequest::daEseguire()
                // Le priorita' prima: primo deploy di un sito nuovo.
                ->orderByRaw("CASE WHEN reason = 'site.created' THEN 0 ELSE 1 END")
                ->orderBy('run_after')
                ->lockForUpdate()
                ->first();

            if ($r === null) {
                return null;
            }

            $r->update([
                'status' => 'running',
                'started_at' => now(),
                'attempts' => $r->attempts + 1,
            ]);

            return $r;
        });
    }

    private function esegui(BuildRequest $richiesta): void
    {
        $site = Site::withoutTenancy()->find($richiesta->site_id);

        if ($site === null) {
            $richiesta->update(['status' => 'failed', 'last_error' => 'sito inesistente', 'finished_at' => now()]);

            return;
        }

        $this->info("build {$richiesta->id}: {$site->domain} ({$richiesta->scope}, {$richiesta->reason})");

        $script = base_path('../scripts/deploy-frontend.sh');

        if (! is_executable($script)) {
            $richiesta->update([
                'status' => 'failed',
                'last_error' => "script di deploy non eseguibile: {$script}",
                'finished_at' => now(),
            ]);
            $this->error('  script di deploy non trovato.');

            return;
        }

        $risultato = Process::timeout(600)
            ->env(['SLIMCMS_SITE' => $site->domain])
            ->run($script);

        if ($risultato->successful()) {
            $richiesta->update(['status' => 'done', 'finished_at' => now(), 'last_error' => null]);
            $this->info('  OK');

            return;
        }

        // Ritentabile finche' restano tentativi: un servizio di build
        // temporaneamente irraggiungibile non deve far perdere la richiesta.
        $ritentabile = $richiesta->attempts < self::TENTATIVI_MASSIMI;

        $richiesta->update([
            'status' => $ritentabile ? 'pending' : 'failed',
            // Backoff crescente: 1, 4, 9 minuti.
            'run_after' => $ritentabile ? now()->addMinutes($richiesta->attempts ** 2) : $richiesta->run_after,
            'last_error' => mb_substr(trim($risultato->errorOutput() ?: $risultato->output()), -2000),
            'finished_at' => $ritentabile ? null : now(),
        ]);

        $this->error($ritentabile
            ? "  fallita, ritento (tentativo {$richiesta->attempts}/" . self::TENTATIVI_MASSIMI . ')'
            : '  FALLITA definitivamente dopo ' . self::TENTATIVI_MASSIMI . ' tentativi');
    }

    private function forza(string $dominio): int
    {
        $site = Site::withoutTenancy()->where('domain', $dominio)->first();

        if ($site === null) {
            $this->error("Nessun sito con dominio {$dominio}.");

            return self::FAILURE;
        }

        $r = BuildRequest::create([
            'site_id' => $site->id,
            'reason' => 'manual',
            'scope' => 'full',
            'status' => 'running',
            'run_after' => now(),
            'started_at' => now(),
            'attempts' => 1,
        ]);

        $this->esegui($r);

        return $r->fresh()->status === 'done' ? self::SUCCESS : self::FAILURE;
    }
}
