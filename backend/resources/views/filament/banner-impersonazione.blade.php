@php
    $idImpersonazione = session(\App\Http\Controllers\ImpersonazioneController::CHIAVE);
    $imp = $idImpersonazione ? \App\Models\Impersonazione::with('adminUser')->find($idImpersonazione) : null;
@endphp

@if ($imp)
    {{-- Deve essere impossibile non accorgersene: chi sta impersonando puo'
         modificare i contenuti di un cliente credendo di essere altrove.
         Su telefono il testo si accorcia e il pulsante va a capo intero,
         invece di stringersi fino a diventare intoccabile. --}}
    <div class="slimcms-impersonazione">
        <span class="slimcms-impersonazione__testo">
            <span class="slimcms-impersonazione__lungo">Stai lavorando come</span>
            <strong>{{ $imp->user?->name }}</strong>
            su <strong>{{ $imp->site?->domain }}</strong>.
            <span class="slimcms-impersonazione__lungo">
                Accesso aperto da {{ $imp->adminUser?->name }}.
            </span>
        </span>

        <form method="POST" action="{{ route('impersona.esci') }}">
            @csrf
            <button type="submit">
                <span class="slimcms-impersonazione__lungo">Esci e torna alla gestione</span>
                <span class="slimcms-impersonazione__corto">Esci</span>
            </button>
        </form>
    </div>

    <style>
        .slimcms-impersonazione {
            position: sticky;
            top: 0;
            z-index: 50;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: center;
            gap: .5rem 1rem;
            padding: .6rem 1rem;
            background: #9c3d2e;
            color: #fff;
            font-size: .875rem;
            line-height: 1.4;
        }

        .slimcms-impersonazione__testo { min-width: 0; }
        .slimcms-impersonazione form { margin: 0; flex: none; }

        .slimcms-impersonazione button {
            background: #fff;
            color: #9c3d2e;
            border: 0;
            border-radius: 6px;
            padding: .4rem 1rem;
            font-weight: 600;
            font-size: inherit;
            cursor: pointer;
            /* Bersaglio comodo da toccare: sotto i 44px un pulsante su
               telefono si sbaglia. */
            min-height: 2.5rem;
        }

        .slimcms-impersonazione__corto { display: none; }

        /* Sotto i 640px il testo lungo sparisce: su una riga sola ci deve
           stare l'informazione essenziale, chi sei e dove. */
        @media (max-width: 640px) {
            .slimcms-impersonazione {
                justify-content: space-between;
                text-align: start;
                font-size: .8125rem;
            }
            .slimcms-impersonazione__lungo { display: none; }
            .slimcms-impersonazione__corto { display: inline; }
        }
    </style>
@endif
