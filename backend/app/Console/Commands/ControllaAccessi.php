<?php

namespace App\Console\Commands;

use App\Models\Accesso;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use App\Mail\AvvisoAccessi;
use Illuminate\Support\Facades\Mail;

/**
 * Avvisa quando qualcuno insiste a entrare, e pota il registro.
 *
 * Un elenco di accessi che nessuno guarda non protegge da niente: il valore
 * sta nell'essere avvisati. La soglia e' su una **finestra breve** e per
 * singolo indirizzo o email, non sul totale: dieci tentativi falliti in un
 * mese sono persone che sbagliano la password, dieci in un'ora sono
 * qualcuno che prova.
 *
 * Un solo alert per chiave ogni sei ore. Un alert che arriva ogni cinque
 * minuti si impara a ignorare, ed e' peggio di nessun alert — lo stesso
 * ragionamento dei TLD esclusi da `slimcms:monitora-certificati`.
 */
class ControllaAccessi extends Command
{
    protected $signature = 'slimcms:controlla-accessi
        {--minuti=60 : la finestra da guardare}
        {--soglia=8 : quanti tentativi falliti fanno scattare l\'avviso}
        {--giorni=180 : dopo quanti giorni si potano le righe}';

    protected $description = 'Avvisa sui tentativi di accesso ripetuti e pota il registro';

    public function handle(): int
    {
        $minuti = max(1, (int) $this->option('minuti'));
        $soglia = max(2, (int) $this->option('soglia'));

        $sospetti = [];

        foreach (['ip', 'email'] as $chiave) {
            $righe = Accesso::query()
                ->falliti()
                ->recenti($minuti)
                ->whereNotNull($chiave)
                ->selectRaw("{$chiave} as valore, COUNT(*) as quanti")
                ->groupBy($chiave)
                ->having('quanti', '>=', $soglia)
                ->get();

            foreach ($righe as $r) {
                $sospetti[] = ['tipo' => $chiave, 'valore' => $r->valore, 'quanti' => (int) $r->quanti];
            }
        }

        $mandati = 0;

        foreach ($sospetti as $s) {
            // Una chiave per sospetto: cosi' due indirizzi diversi generano
            // due avvisi, ma lo stesso indirizzo non ne genera dodici.
            $memoria = "accessi:avvisato:{$s['tipo']}:" . md5($s['valore']);

            if (Cache::has($memoria)) {
                continue;
            }

            $this->avvisa($s, $minuti);
            Cache::put($memoria, true, now()->addHours(6));
            $mandati++;
        }

        $potate = Accesso::query()
            ->where('created_at', '<', now()->subDays(max(7, (int) $this->option('giorni'))))
            ->delete();

        $this->info(sprintf(
            '%d sospetti, %d avvisi mandati, %d righe potate.',
            count($sospetti), $mandati, $potate
        ));

        return self::SUCCESS;
    }

    private function avvisa(array $sospetto, int $minuti): void
    {
        $destinatario = config('slimcms.email_alert');

        if (blank($destinatario)) {
            $this->warn('  Nessun destinatario configurato (slimcms.email_alert).');

            return;
        }

        $ultimi = Accesso::query()
            ->falliti()
            ->recenti($minuti)
            ->where($sospetto['tipo'], $sospetto['valore'])
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn (Accesso $a) => sprintf(
                '  %s  %s  %s  %s',
                $a->created_at?->format('d/m H:i:s'),
                str_pad($a->pannello(), 22),
                str_pad((string) $a->email, 30),
                (string) $a->ip
            ))
            ->all();

        Mail::to($destinatario)->send(new AvvisoAccessi($sospetto, $minuti, $ultimi));

        $this->warn("  Avviso mandato per {$sospetto['valore']} ({$sospetto['quanti']} tentativi).");
    }
}
