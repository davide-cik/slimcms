{{-- Grafico in CSS puro: nessuna libreria da caricare nel pannello, e
     funziona anche quando la connessione a un CDN non c'e'. --}}
<x-filament-panels::page>
    @php
        $colori = [
            'umano' => '#0f6b4a',
            'motore' => '#2563eb',
            'ai' => '#a855f7',
            'bot' => '#94a3b8',
        ];
    @endphp

    <div class="flex flex-wrap gap-2">
        @foreach ([7 => '7 giorni', 30 => '30 giorni', 90 => '90 giorni'] as $g => $etichetta)
            <x-filament::button
                :color="$periodo === $g ? 'primary' : 'gray'"
                size="sm"
                wire:click="cambiaPeriodo({{ $g }})"
            >{{ $etichetta }}</x-filament::button>
        @endforeach
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <x-filament::section>
            <div class="text-2xl font-bold">{{ number_format($visitatori, 0, ',', '.') }}</div>
            <div class="text-sm opacity-70">Persone distinte</div>
        </x-filament::section>

        @foreach ($categorie as $chiave => $etichetta)
            <x-filament::section>
                <div class="text-2xl font-bold" style="color: {{ $colori[$chiave] }}">
                    {{ number_format($totali[$chiave], 0, ',', '.') }}
                </div>
                <div class="text-sm opacity-70">{{ $etichetta }}</div>
            </x-filament::section>
        @endforeach
    </div>

    <x-filament::section heading="Accessi giorno per giorno">
        <div style="display:flex;align-items:flex-end;gap:2px;height:220px;overflow-x:auto">
            @foreach ($giorni as $giorno => $per)
                @php $totaleGiorno = array_sum($per); @endphp
                <div
                    style="flex:1 0 8px;display:flex;flex-direction:column-reverse;height:100%;justify-content:flex-start"
                    title="{{ \Illuminate\Support\Carbon::parse($giorno)->format('d/m/Y') }} — {{ $totaleGiorno }} accessi"
                >
                    @foreach ($categorie as $chiave => $etichetta)
                        @if ($per[$chiave] > 0)
                            <div style="background: {{ $colori[$chiave] }}; height: {{ ($per[$chiave] / $massimo) * 100 }}%"></div>
                        @endif
                    @endforeach
                </div>
            @endforeach
        </div>

        <div class="mt-3 flex flex-wrap gap-4 text-sm">
            @foreach ($categorie as $chiave => $etichetta)
                <span class="flex items-center gap-2">
                    <span style="width:10px;height:10px;border-radius:2px;background:{{ $colori[$chiave] }};display:inline-block"></span>
                    {{ $etichetta }}
                </span>
            @endforeach
        </div>
    </x-filament::section>

    <div class="grid gap-4 lg:grid-cols-2">
        <x-filament::section heading="Pagine più viste (persone)">
            @forelse ($pagine as $p)
                <div class="flex justify-between gap-4 py-1 text-sm">
                    <span class="truncate">{{ $p->percorso }}</span>
                    <span class="font-semibold">{{ number_format($p->totale, 0, ',', '.') }}</span>
                </div>
            @empty
                <p class="text-sm opacity-70">Ancora nessuna visita registrata.</p>
            @endforelse
        </x-filament::section>

        <x-filament::section heading="Chi passa di qui">
            @forelse ($agenti as $a)
                <div class="flex items-center justify-between gap-3 py-1 text-sm">
                    <span class="flex items-center gap-2 truncate">
                        <span style="width:8px;height:8px;border-radius:99px;background:{{ $colori[$a->categoria] ?? '#999' }};display:inline-block"></span>
                        {{ $a->agente }}
                    </span>
                    <span class="font-semibold">{{ number_format($a->totale, 0, ',', '.') }}</span>
                </div>
            @empty
                <p class="text-sm opacity-70">Ancora nessun dato.</p>
            @endforelse
        </x-filament::section>
    </div>

    @if ($sospetti > 0)
        <x-filament::section heading="Da guardare">
            <p class="text-sm">
                <strong>{{ number_format($sospetti, 0, ',', '.') }}</strong> accessi dichiarano un browser
                ma non hanno mai eseguito JavaScript. Quasi sempre sono scanner travestiti da Chrome:
                sono contati fra le persone perché non c'è modo di esserne certi — un browser vecchio o
                con JavaScript disattivato esiste — ma difficilmente sono visite vere.
            </p>
        </x-filament::section>
    @endif

    <x-filament::section heading="Come sono contati" collapsible collapsed>
        <div class="prose prose-sm max-w-none dark:prose-invert">
            <p>
                Il sito è statico: una visita non passa da nessun programma nostro, quindi non lascia
                traccia da sola. Ogni pagina cita un contatore in PHP servito dal dominio del sito, che
                annota indirizzo, user-agent e provenienza in una cartella privata. Un compito
                automatico li porta qui ogni pochi minuti. <strong>Non si leggono i log del server.</strong>
            </p>
            <p>
                È un'immagine e non solo uno script perché i bot dei modelli generativi non eseguono
                JavaScript: con il solo script sarebbero invisibili. Chi esegue anche JavaScript manda
                un secondo segnale, ed è così che si distingue una persona da uno scanner che si
                dichiara Chrome.
            </p>
            <p>
                <strong>Nessun cookie e nessun indirizzo IP conservato.</strong> Le persone distinte si
                contano con un'impronta calcolata con un valore che cambia ogni giorno e non viene
                salvato: si sa quanti sono oggi, non chi erano ieri.
            </p>
            <p>
                Resta fuori chi scarica solo l'HTML e nient'altro — tipicamente gli scanner di
                vulnerabilità. Quelli che cercano indirizzi inesistenti li trovi comunque in
                <em>Pagine mancanti</em>.
            </p>
        </div>
    </x-filament::section>
</x-filament-panels::page>
