<?php

namespace App\Filament\Pages;

use App\Enums\Ruolo;
use App\Models\Vista;
use App\Models\VistaImpronta;
use App\Support\ClassificatoreAgente;
use App\Support\RuoloCorrente;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Gli accessi al sito, giorno per giorno, divisi per chi li ha fatti.
 *
 * I dati arrivano dal contatore sul dominio del sito (`slimcms-vista.php`),
 * importati da `slimcms:importa-viste`. Non dai log del server.
 *
 * Non e' una risorsa Filament e quindi non ha una policy: le pagine non
 * hanno un modello. Il controllo d'accesso lo dichiara `canAccess()`, e
 * `PolicyRuoliTest` pretende che ogni pagina del pannello lo faccia.
 */
class Statistiche extends Page
{
    protected string $view = 'filament.pages.statistiche';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Statistiche';

    protected static ?string $title = 'Statistiche del sito';

    protected static ?string $slug = 'statistiche';

    protected static ?int $navigationSort = 90;

    /**
     * Quanti giorni mostrare. Un mese e' la finestra in cui si nota una
     * tendenza.
     *
     * Si chiama `periodo` e non `giorni` perche' le proprieta' pubbliche di
     * un componente Livewire arrivano alla vista e coprono i dati passati
     * con lo stesso nome: la serie per giorno sparirebbe dietro un intero.
     */
    public int $periodo = 30;

    public static function canAccess(): bool
    {
        // Gli accessi al sito sono informazione di gestione, non contenuto:
        // dal grado di redattore in su, come i reindirizzamenti.
        return (bool) RuoloCorrente::nelPannello()?->almeno(Ruolo::Editor);
    }

    public function cambiaPeriodo(int $giorni): void
    {
        $this->periodo = in_array($giorni, [7, 30, 90], true) ? $giorni : 30;
    }

    /** @return array<string, mixed> */
    protected function datiPerLaVista(): array
    {
        $da = Carbon::today()->subDays($this->periodo - 1);

        $righe = Vista::query()
            ->where('giorno', '>=', $da)
            ->get();

        // Un giorno senza visite deve comparire come zero, non mancare: un
        // grafico che salta i giorni vuoti racconta una continuita' che non
        // c'e' stata.
        $giorni = [];
        for ($d = $da->copy(); $d->lte(Carbon::today()); $d->addDay()) {
            $giorni[$d->toDateString()] = array_fill_keys(array_keys(ClassificatoreAgente::CATEGORIE), 0);
        }

        foreach ($righe as $r) {
            $g = $r->giorno->toDateString();

            if (isset($giorni[$g][$r->categoria])) {
                $giorni[$g][$r->categoria] += $r->conteggio;
            }
        }

        $totali = array_fill_keys(array_keys(ClassificatoreAgente::CATEGORIE), 0);

        foreach ($giorni as $per) {
            foreach ($per as $cat => $n) {
                $totali[$cat] += $n;
            }
        }

        return [
            'giorni' => $giorni,
            'totali' => $totali,
            'massimo' => max(1, max(array_map(fn ($g) => array_sum($g), $giorni) ?: [1])),
            'visitatori' => VistaImpronta::query()->where('giorno', '>=', $da)->count(),
            'pagine' => Vista::query()
                ->where('giorno', '>=', $da)
                ->where('categoria', ClassificatoreAgente::UMANO)
                ->select('percorso', DB::raw('SUM(conteggio) as totale'))
                ->groupBy('percorso')
                ->orderByDesc('totale')
                ->limit(10)
                ->get(),
            'agenti' => Vista::query()
                ->where('giorno', '>=', $da)
                ->select('categoria', 'agente', DB::raw('SUM(conteggio) as totale'), DB::raw('SUM(con_js) as js'))
                ->groupBy('categoria', 'agente')
                ->orderByDesc('totale')
                ->limit(15)
                ->get(),
            // Chi dichiara un browser e non esegue mai JavaScript e' quasi
            // sempre uno scanner travestito. Non lo si riclassifica d'ufficio
            // — un browser vecchio o con JS disattivato esiste — ma si dice.
            'sospetti' => Vista::query()
                ->where('giorno', '>=', $da)
                ->where('categoria', ClassificatoreAgente::UMANO)
                ->where('con_js', 0)
                ->sum('conteggio'),
            'categorie' => ClassificatoreAgente::CATEGORIE,
        ];
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return $this->datiPerLaVista();
    }
}
