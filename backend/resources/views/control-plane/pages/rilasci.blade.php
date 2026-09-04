<x-filament-panels::page>
    <div class="space-y-6">

        <x-filament::section>
            <x-slot name="heading">Versione in esecuzione: {{ $versioneCorrente }}</x-slot>
            <x-slot name="description">
                {{ $totale }} {{ $totale === 1 ? 'rilascio' : 'rilasci' }} in tutto.
                Ogni commit è un rilascio e vale 0.0.1: la versione si deriva dalla posizione
                nella storia, quindi non può divergere dal codice che gira davvero.
            </x-slot>
        </x-filament::section>

        @if ($rilasci->isEmpty())
            <x-filament::section>
                <p class="text-sm">
                    Nessun rilascio da mostrare. In produzione l'elenco viene generato al deploy
                    in <code>rilasci.json</code>, perché l'applicazione è una copia senza
                    <code>.git</code>: se manca, rilancia <code>scripts/deploy-backend.sh</code>.
                </p>
            </x-filament::section>
        @else
            <div class="space-y-3">
                @foreach ($rilasci as $r)
                    <x-filament::section
                        collapsible
                        collapsed
                        :persist-collapsed="false"
                        :id="'rilascio-' . $r['hash_breve']"
                    >
                        <x-slot name="heading">
                            <span class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                                <span @class([
                                    'font-mono text-sm',
                                    'text-primary-600 dark:text-primary-400 font-semibold' => $r['versione'] === $versioneCorrente,
                                ])>{{ $r['versione'] }}</span>
                                <span class="font-normal">{{ $r['titolo'] }}</span>
                            </span>
                        </x-slot>

                        <x-slot name="description">
                            <span class="font-mono">{{ $r['hash_breve'] }}</span>
                            · {{ $r['autore'] }}
                            · {{ \Illuminate\Support\Carbon::parse($r['data'])->translatedFormat('j F Y, H:i') }}
                            @if ($r['versione'] === $versioneCorrente)
                                · <strong>in esecuzione</strong>
                            @endif
                        </x-slot>

                        @if (filled($r['corpo']))
                            <pre class="whitespace-pre-wrap text-sm leading-relaxed">{{ $r['corpo'] }}</pre>
                        @else
                            <p class="text-sm italic opacity-70">Nessuna descrizione estesa.</p>
                        @endif
                    </x-filament::section>
                @endforeach
            </div>

            @if ($pagineTotali > 1)
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-sm opacity-70">
                        Pagina {{ $pagina }} di {{ $pagineTotali }}, {{ $perPagina }} rilasci per pagina.
                    </p>

                    <div class="flex flex-wrap gap-2">
                        @if ($pagina > 1)
                            <x-filament::button wire:click="vaiA({{ $pagina - 1 }})" color="gray" size="sm">
                                Più recenti
                            </x-filament::button>
                        @endif

                        @if ($pagina < $pagineTotali)
                            <x-filament::button wire:click="vaiA({{ $pagina + 1 }})" color="gray" size="sm">
                                Più vecchi
                            </x-filament::button>
                        @endif
                    </div>
                </div>
            @endif
        @endif

    </div>
</x-filament-panels::page>
