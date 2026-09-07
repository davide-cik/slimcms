{{--
    Le statistiche del sito.

    Grafico in CSS puro: nessuna libreria da caricare nel pannello, e funziona
    anche quando un CDN non risponde.

    I colori delle serie NON sono scelti a occhio: sono i primi quattro slot
    della tavolozza categorica di riferimento, verificati con lo script del
    metodo (validate_palette.js) su tutti e due i fondi — banda di luminosita',
    soglia di croma, separazione per daltonismo e contrasto. In chiaro due
    tinte stanno sotto 3:1 sul fondo, e la regola in quel caso impone di
    aggiungere etichette visibili o una vista tabella: ci sono tutte e due
    (legenda con i totali scritti, piu' «I numeri, in tabella» qui sotto).

    Il testo non porta mai il colore della serie: i segni colorati stanno nei
    marcatori, le cifre restano in inchiostro primario o attenuato.
--}}
<x-filament-panels::page>
    @php
        // Ordine fisso, mai ciclato: la categoria tiene la sua tinta anche se
        // un giorno ne sparisce una.
        $ordine = ['umano', 'motore', 'ai', 'bot'];
        $etichetteCorte = [
            'umano' => 'Persone',
            'motore' => 'Motori',
            'ai' => 'Bot AI',
            'bot' => 'Altri bot',
        ];
        $massimoGiornaliero = max(1, max(array_map(fn ($g) => array_sum($g), $giorni) ?: [1]));
        // Tacche tonde: 0 e due valori leggibili, non il massimo esatto.
        // Il passo si aggancia a 1, 2, 2.5 o 5 per decade — 85 e' un multiplo
        // di cinque ma non e' un numero che si legge, 100 si'.
        $grezzo = $massimoGiornaliero / 2;
        $decade = 10 ** max(0, (int) floor(log10(max(1, $grezzo))));
        $passo = (int) ($decade * collect([1, 2, 2.5, 5, 10])
            ->first(fn ($m) => $decade * $m >= $grezzo, 10));
        $cima = max($passo * 2, $massimoGiornaliero);
        $nGiorni = count($giorni);
    @endphp

    <div class="viz">
        {{-- ---------------------------------------------------- periodo --}}
        <div class="flex flex-wrap items-center gap-2">
            @foreach ([7 => '7 giorni', 30 => '30 giorni', 90 => '90 giorni'] as $g => $etichetta)
                <x-filament::button
                    :color="$periodo === $g ? 'primary' : 'gray'"
                    size="sm"
                    wire:click="cambiaPeriodo({{ $g }})"
                >{{ $etichetta }}</x-filament::button>
            @endforeach
        </div>

        {{-- ------------------------------------------------ numeri chiave --}}
        <div class="grid gap-4 sm:grid-cols-2">
            @foreach ([
                ['Persone distinte', $visitatori, $variazione['persone'], 'Contate senza cookie: un\'impronta il cui sale cambia ogni giorno.'],
                ['Accessi totali', $accessi, $variazione['accessi'], 'Persone, motori, bot AI e scanner messi insieme.'],
            ] as [$titolo, $valore, $var, $nota])
                <x-filament::section>
                    <div class="flex items-baseline gap-3">
                        <span class="viz-cifra">{{ number_format($valore, 0, ',', '.') }}</span>

                        @if ($var)
                            <span class="viz-delta">
                                {{ $var['segno'] > 0 ? '▲' : ($var['segno'] < 0 ? '▼' : '=') }}
                                {{ $var['percento'] }}%
                            </span>
                        @endif
                    </div>

                    <div class="viz-etichetta">{{ $titolo }}</div>
                    <p class="viz-nota">
                        {{ $nota }}
                        @if ($var)
                            Rispetto ai {{ $periodo }} giorni precedenti.
                        @endif
                    </p>
                </x-filament::section>
            @endforeach
        </div>

        {{-- ------------------------------------------------------ grafico --}}
        <x-filament::section heading="Accessi giorno per giorno">
            {{-- La legenda c'e' sempre con due o piu' serie: e' il canale di
                 identita' su cui si puo' contare, e porta anche i totali, che
                 e' la parte che assolve il contrasto basso in chiaro. --}}
            <div class="viz-legenda">
                @foreach ($ordine as $chiave)
                    <span class="viz-voce">
                        <span class="viz-pastiglia" data-serie="{{ $chiave }}"></span>
                        <span class="viz-voce-nome">{{ $categorie[$chiave] }}</span>
                        <span class="viz-voce-valore">{{ number_format($totali[$chiave], 0, ',', '.') }}</span>
                    </span>
                @endforeach
            </div>

            <div class="viz-telaio">
                {{-- Griglia sottile, continua, che si ritira: porta i valori
                     che non sono etichettati direttamente. --}}
                <div class="viz-griglia" aria-hidden="true">
                    @foreach ([$cima, $passo, 0] as $tacca)
                        <div class="viz-riga"><span class="viz-tacca">{{ number_format($tacca, 0, ',', '.') }}</span></div>
                    @endforeach
                </div>

                <div class="viz-colonne" role="img"
                     aria-label="Accessi giornalieri divisi per tipo di visitatore. I numeri esatti sono nella tabella qui sotto.">
                    @foreach ($giorni as $giorno => $per)
                        @php
                            $totaleGiorno = array_sum($per);
                            $data = \Illuminate\Support\Carbon::parse($giorno);
                        @endphp
                        <div class="viz-slot" tabindex="0"
                             aria-label="{{ $data->translatedFormat('j F') }}: {{ $totaleGiorno }} accessi">
                            @if ($giorno === $giornoPieno && $totaleGiorno > 0)
                                {{-- Una sola etichetta diretta, sul giorno piu' alto:
                                     una cifra su ogni colonna non la legge nessuno. --}}
                                <span class="viz-cima">{{ number_format($totaleGiorno, 0, ',', '.') }}</span>
                            @endif

                            <div class="viz-colonna" style="height: {{ ($totaleGiorno / $cima) * 100 }}%">
                                @foreach (array_reverse($ordine) as $chiave)
                                    @if ($per[$chiave] > 0)
                                        <div class="viz-segmento"
                                             data-serie="{{ $chiave }}"
                                             style="flex: {{ $per[$chiave] }} 0 auto"></div>
                                    @endif
                                @endforeach
                            </div>

                            <div class="viz-suggerimento" role="tooltip">
                                <strong>{{ $data->translatedFormat('j F Y') }}</strong>
                                @foreach ($ordine as $chiave)
                                    <span>
                                        <span class="viz-pastiglia" data-serie="{{ $chiave }}"></span>
                                        {{ $etichetteCorte[$chiave] }}
                                        <b>{{ number_format($per[$chiave], 0, ',', '.') }}</b>
                                    </span>
                                @endforeach
                                <span class="viz-suggerimento-totale">
                                    Totale <b>{{ number_format($totaleGiorno, 0, ',', '.') }}</b>
                                </span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="viz-asse">
                <span>{{ \Illuminate\Support\Carbon::parse(array_key_first($giorni))->translatedFormat('j M') }}</span>
                <span>{{ \Illuminate\Support\Carbon::parse(array_key_last($giorni))->translatedFormat('j M') }}</span>
            </div>
        </x-filament::section>

        {{-- ------------------------------------------- elenchi ordinati --}}
        <div class="grid gap-4 lg:grid-cols-2">
            <x-filament::section heading="Pagine più viste">
                <p class="viz-nota viz-nota-testa">Solo le visite di persone.</p>

                @php $maxPagina = max(1, (int) ($pagine->max('totale') ?? 1)); @endphp

                @forelse ($pagine as $p)
                    <div class="viz-classifica">
                        <span class="viz-classifica-nome" title="{{ $p->percorso }}">{{ $p->percorso }}</span>
                        <span class="viz-barra">
                            {{-- Una sola serie, una sola tinta: colorare ogni
                                 barra in base alla sua lunghezza ridisegna con
                                 la tinta un'informazione che la barra gia' da'. --}}
                            <span class="viz-barra-piena" data-serie="umano"
                                  style="width: {{ ($p->totale / $maxPagina) * 100 }}%"></span>
                        </span>
                        <span class="viz-classifica-valore">{{ number_format($p->totale, 0, ',', '.') }}</span>
                    </div>
                @empty
                    <p class="viz-nota">Ancora nessuna visita registrata.</p>
                @endforelse
            </x-filament::section>

            <x-filament::section heading="Chi passa di qui">
                <p class="viz-nota viz-nota-testa">Il colore e' quello della legenda qui sopra.</p>

                @php $maxAgente = max(1, (int) ($agenti->max('totale') ?? 1)); @endphp

                @forelse ($agenti as $a)
                    <div class="viz-classifica">
                        <span class="viz-classifica-nome" title="{{ $a->agente }}">
                            <span class="viz-pastiglia" data-serie="{{ $a->categoria }}"></span>{{ $a->agente }}
                        </span>
                        <span class="viz-barra">
                            <span class="viz-barra-piena" data-serie="{{ $a->categoria }}"
                                  style="width: {{ ($a->totale / $maxAgente) * 100 }}%"></span>
                        </span>
                        <span class="viz-classifica-valore">{{ number_format($a->totale, 0, ',', '.') }}</span>
                    </div>
                @empty
                    <p class="viz-nota">Ancora nessun dato.</p>
                @endforelse
            </x-filament::section>
        </div>

        @if ($sospetti > 0)
            <x-filament::section heading="Da guardare">
                <p class="viz-nota">
                    <strong>{{ number_format($sospetti, 0, ',', '.') }}</strong> accessi dichiarano un browser
                    ma non hanno mai eseguito JavaScript. Quasi sempre sono scanner travestiti da Chrome:
                    sono contati fra le persone perché non c'è modo di esserne certi — un browser vecchio o
                    con JavaScript disattivato esiste — ma difficilmente sono visite vere.
                </p>
            </x-filament::section>
        @endif

        {{-- La vista tabella non e' un extra: e' quella che assolve le due
             tinte a basso contrasto in chiaro, e l'unico modo di leggere un
             numero esatto senza passare dal passaggio del mouse. --}}
        <x-filament::section heading="I numeri, in tabella" collapsible collapsed>
            <div class="viz-tabella-scorre">
                <table class="viz-tabella">
                    <thead>
                        <tr>
                            <th scope="col">Giorno</th>
                            @foreach ($ordine as $chiave)
                                <th scope="col">{{ $etichetteCorte[$chiave] }}</th>
                            @endforeach
                            <th scope="col">Totale</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach (array_reverse($giorni, true) as $giorno => $per)
                            <tr>
                                <th scope="row">{{ \Illuminate\Support\Carbon::parse($giorno)->translatedFormat('j M Y') }}</th>
                                @foreach ($ordine as $chiave)
                                    <td>{{ number_format($per[$chiave], 0, ',', '.') }}</td>
                                @endforeach
                                <td class="viz-tabella-totale">{{ number_format(array_sum($per), 0, ',', '.') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        <x-filament::section heading="Come sono contati" collapsible collapsed>
            <div class="viz-prosa">
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
    </div>

    <style>
        /* I quattro slot categorici, verificati dallo script del metodo su
           tutti e due i fondi. Definiti come ruoli e non come esadecimali
           sparsi: chiaro e scuro cambiano in un posto solo. */
        .viz {
            --serie-umano:  #2a78d6;
            --serie-motore: #eb6834;
            --serie-ai:     #1baf7a;
            --serie-bot:    #eda100;
            --viz-fondo:    #fcfcfb;
            --viz-griglia:  rgb(0 0 0 / 0.10);
            --viz-inchiostro:  rgb(0 0 0 / 0.92);
            --viz-attenuato:   rgb(0 0 0 / 0.55);
            --viz-tenue:       rgb(0 0 0 / 0.35);
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        /* Filament mette `.dark` sull'elemento radice e risolve gia' lui il
           caso "come il sistema": qui basta un ambito solo. I passi scuri sono
           scelti per il fondo scuro, non un ribaltamento automatico di quelli
           chiari. */
        .dark .viz {
            --serie-umano:  #3987e5;
            --serie-motore: #d95926;
            --serie-ai:     #199e70;
            --serie-bot:    #c98500;
            --viz-fondo:    #1a1a19;
            --viz-griglia:  rgb(255 255 255 / 0.14);
            --viz-inchiostro:  rgb(255 255 255 / 0.92);
            --viz-attenuato:   rgb(255 255 255 / 0.60);
            --viz-tenue:       rgb(255 255 255 / 0.38);
        }

        [data-serie='umano']  { --serie: var(--serie-umano); }
        [data-serie='motore'] { --serie: var(--serie-motore); }
        [data-serie='ai']     { --serie: var(--serie-ai); }
        [data-serie='bot']    { --serie: var(--serie-bot); }

        /* ---------------------------------------------- numeri chiave --- */

        .viz-cifra {
            font-size: 2.1rem;
            font-weight: 700;
            line-height: 1;
            letter-spacing: -0.02em;
            font-variant-numeric: tabular-nums;
            color: var(--viz-inchiostro);
        }

        .viz-delta {
            font-size: 0.8rem;
            font-weight: 600;
            font-variant-numeric: tabular-nums;
            color: var(--viz-attenuato);
        }

        .viz-etichetta {
            margin-top: 0.35rem;
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--viz-inchiostro);
        }

        .viz-nota {
            margin-top: 0.3rem;
            font-size: 0.8rem;
            line-height: 1.5;
            color: var(--viz-attenuato);
        }

        .viz-nota-testa { margin: 0 0 0.9rem; }

        /* ---------------------------------------------------- grafico --- */

        .viz-legenda {
            display: flex;
            flex-wrap: wrap;
            gap: 0.35rem 1.4rem;
            margin-bottom: 1.25rem;
        }

        .viz-voce {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            font-size: 0.85rem;
        }

        .viz-voce-nome  { color: var(--viz-attenuato); }
        .viz-voce-valore {
            font-weight: 600;
            font-variant-numeric: tabular-nums;
            color: var(--viz-inchiostro);
        }

        .viz-pastiglia {
            width: 9px;
            height: 9px;
            border-radius: 2px;
            background: var(--serie, var(--viz-tenue));
            flex: 0 0 auto;
            display: inline-block;
        }

        .viz-telaio {
            position: relative;
            height: 240px;
            padding-left: 3.2rem;
        }

        .viz-griglia {
            position: absolute;
            inset: 0 0 0 0;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        /* Tratto sottile, continuo, mai tratteggiato: deve ritirarsi. */
        .viz-riga {
            position: relative;
            border-top: 1px solid var(--viz-griglia);
        }

        .viz-tacca {
            position: absolute;
            left: 0;
            top: -0.55em;
            width: 3rem;
            text-align: right;
            padding-right: 0.5rem;
            font-size: 0.7rem;
            font-variant-numeric: tabular-nums;
            color: var(--viz-tenue);
        }

        .viz-colonne {
            position: absolute;
            inset: 0 0 0 3.2rem;
            display: flex;
            align-items: flex-end;
            gap: 2px;
        }

        .viz-slot {
            position: relative;
            flex: 1 1 0;
            /* Il segno non riempie mai la fascia: il resto e' aria. */
            max-width: 24px;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            align-items: center;
        }

        .viz-slot:focus-visible { outline: 2px solid var(--serie-umano); outline-offset: 2px; }

        .viz-colonna {
            width: 100%;
            display: flex;
            flex-direction: column;
            /* Lo stacco fra i segmenti e' un vuoto nel colore del fondo, non
               un bordo: un contorno aggiunge inchiostro che non e' dato. */
            gap: 2px;
            /* Estremita' arrotondata in cima, squadrata sulla linea di base. */
            border-radius: 4px 4px 0 0;
            overflow: visible;
        }

        .viz-segmento { background: var(--serie); min-height: 2px; }
        .viz-colonna > .viz-segmento:first-child { border-radius: 4px 4px 0 0; }

        .viz-cima {
            font-size: 0.68rem;
            font-weight: 600;
            font-variant-numeric: tabular-nums;
            color: var(--viz-attenuato);
            margin-bottom: 0.2rem;
            white-space: nowrap;
        }

        .viz-asse {
            display: flex;
            justify-content: space-between;
            margin: 0.5rem 0 0 3.2rem;
            font-size: 0.7rem;
            color: var(--viz-tenue);
        }

        /* Il suggerimento al passaggio: un grafico in HTML e' interattivo, e
           il bersaglio e' tutta la fascia, non il segno sottile. */
        .viz-suggerimento {
            position: absolute;
            bottom: calc(100% + 0.4rem);
            left: 50%;
            transform: translateX(-50%);
            display: none;
            flex-direction: column;
            gap: 0.15rem;
            z-index: 20;
            padding: 0.55rem 0.7rem;
            border-radius: 6px;
            background: var(--viz-fondo);
            border: 1px solid var(--viz-griglia);
            box-shadow: 0 6px 20px rgb(0 0 0 / 0.18);
            font-size: 0.75rem;
            line-height: 1.5;
            white-space: nowrap;
            color: var(--viz-inchiostro);
        }

        .viz-suggerimento span { display: flex; align-items: center; gap: 0.4rem; }
        .viz-suggerimento b { margin-left: auto; font-variant-numeric: tabular-nums; }
        .viz-suggerimento-totale { border-top: 1px solid var(--viz-griglia); margin-top: 0.25rem; padding-top: 0.25rem; }

        .viz-slot:hover .viz-suggerimento,
        .viz-slot:focus-visible .viz-suggerimento { display: flex; }

        /* --------------------------------------------------- classifiche --- */

        .viz-classifica {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 5.5rem auto;
            align-items: center;
            gap: 0.75rem;
            padding: 0.3rem 0;
            font-size: 0.85rem;
        }

        .viz-classifica-nome {
            display: flex;
            align-items: center;
            gap: 0.45rem;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            color: var(--viz-inchiostro);
        }

        .viz-barra {
            display: block;
            height: 8px;
            border-radius: 2px;
            background: var(--viz-griglia);
            overflow: hidden;
        }

        .viz-barra-piena {
            display: block;
            height: 100%;
            border-radius: 0 4px 4px 0;
            background: var(--serie);
            min-width: 2px;
        }

        .viz-classifica-valore {
            font-weight: 600;
            font-variant-numeric: tabular-nums;
            color: var(--viz-inchiostro);
        }

        /* ------------------------------------------------------ tabella --- */

        .viz-tabella-scorre { overflow-x: auto; }

        .viz-tabella {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.82rem;
            font-variant-numeric: tabular-nums;
        }

        .viz-tabella th,
        .viz-tabella td {
            padding: 0.35rem 0.6rem;
            text-align: right;
            white-space: nowrap;
            color: var(--viz-attenuato);
        }

        .viz-tabella thead th { color: var(--viz-tenue); font-weight: 600; }
        .viz-tabella th[scope='row'] { text-align: left; color: var(--viz-inchiostro); font-weight: 500; }
        .viz-tabella tbody tr + tr th,
        .viz-tabella tbody tr + tr td { border-top: 1px solid var(--viz-griglia); }
        .viz-tabella-totale { color: var(--viz-inchiostro); font-weight: 600; }

        .viz-prosa p { font-size: 0.87rem; line-height: 1.6; color: var(--viz-attenuato); margin-bottom: 0.8rem; }
        .viz-prosa p:last-child { margin-bottom: 0; }
        .viz-prosa strong { color: var(--viz-inchiostro); }

        @media (max-width: 640px) {
            .viz-telaio { height: 180px; }
            .viz-classifica { grid-template-columns: minmax(0, 1fr) 3.5rem auto; }
        }
    </style>
</x-filament-panels::page>
