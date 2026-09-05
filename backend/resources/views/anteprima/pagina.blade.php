<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Un'anteprima non va indicizzata in nessun caso: e' contenuto non
         pubblicato, servito da un dominio che non e' quello del sito. --}}
    <meta name="robots" content="noindex, nofollow">
    <title>Anteprima — {{ $pagina->title }}</title>

    {{-- I fogli di stile VERI del sito pubblicato: nessuna copia del CSS da
         tenere allineata. Se il sito non e' mai stato pubblicato non ce n'e'
         nessuno, e la striscia qui sotto lo dice. --}}
    @foreach ($fogli as $foglio)
        <link rel="stylesheet" href="{{ $foglio }}">
    @endforeach

    <style>
        .anteprima-striscia {
            position: sticky; top: 0; z-index: 999;
            display: flex; flex-wrap: wrap; gap: .75rem; align-items: center;
            padding: .55rem .9rem;
            background: #1f2937; color: #f9fafb;
            font: 600 13px/1.4 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        }
        .anteprima-striscia .stato { padding: .1rem .5rem; border-radius: 99px; background: #f59e0b; color: #1f2937; }
        .anteprima-striscia .stato.online { background: #34d399; }
        .anteprima-striscia .avviso { font-weight: 400; opacity: .85; }
        .anteprima-striscia a { color: inherit; margin-left: auto; }

        .anteprima-segnaposto {
            display: flex; flex-direction: column; gap: .3rem;
            padding: 1rem 1.2rem; border: 1px dashed rgba(128,128,128,.6); border-radius: 8px;
            font: 400 14px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        }
        .anteprima-segnaposto span { word-break: break-all; opacity: .75; }
        .anteprima-segnaposto em { opacity: .7; }
        .anteprima-nota { font-size: .85rem; opacity: .7; }

        /* Senza i fogli del sito la pagina sarebbe illeggibile: questo non
           imita il sito, tiene solo il testo in una colonna. */
        @if (empty($fogli))
            body { max-width: 46rem; margin: 0 auto; padding: 0 1.2rem 4rem;
                   font: 16px/1.6 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
            img { max-width: 100%; height: auto; }
        @endif
    </style>
</head>
<body>
    <div class="anteprima-striscia">
        <span class="stato {{ $pagina->status === 'published' ? 'online' : '' }}">
            {{ ['draft' => 'Bozza', 'published' => 'Pubblicata', 'scheduled' => 'Programmata'][$pagina->status] ?? $pagina->status }}
        </span>

        <span>Anteprima di «{{ $pagina->title }}»</span>

        @if (empty($fogli))
            <span class="avviso">
                Il sito non è ancora pubblicato: qui non c'è la grafica, solo il contenuto.
            </span>
        @else
            <span class="avviso">Testata e piè di pagina non sono mostrati.</span>
        @endif

        <a href="{{ \App\Filament\Resources\Pages\PageResource::getUrl('edit', ['record' => $pagina], tenant: $sito) }}">
            Torna alla modifica
        </a>
    </div>

    <main class="corpo-pagina" data-colonne="{{ $pagina->colonne ?? 1 }}" style="--colonne-corpo:{{ $pagina->colonne ?? 1 }}">
        @include('anteprima.blocchi', ['blocchi' => $blocchi])
    </main>
</body>
</html>
