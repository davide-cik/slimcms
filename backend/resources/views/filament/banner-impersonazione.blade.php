@php
    $idImpersonazione = session(\App\Http\Controllers\ImpersonazioneController::CHIAVE);
    $imp = $idImpersonazione ? \App\Models\Impersonazione::with('adminUser')->find($idImpersonazione) : null;
@endphp

@if ($imp)
    {{-- Deve essere impossibile non accorgersene: chi sta impersonando puo'
         modificare i contenuti di un cliente credendo di essere altrove. --}}
    <div style="background:#9c3d2e;color:#fff;padding:.6rem 1rem;display:flex;
                align-items:center;justify-content:center;gap:1rem;flex-wrap:wrap;
                font-size:.875rem;position:sticky;top:0;z-index:50">
        <span>
            Stai lavorando come <strong>{{ $imp->user?->name }}</strong>
            su <strong>{{ $imp->site?->domain }}</strong>.
            Accesso aperto da {{ $imp->adminUser?->name }}.
        </span>

        <form method="POST" action="{{ route('impersona.esci') }}" style="margin:0">
            @csrf
            <button type="submit"
                    style="background:#fff;color:#9c3d2e;border:0;border-radius:6px;
                           padding:.35rem .9rem;font-weight:600;cursor:pointer">
                Esci e torna alla gestione
            </button>
        </form>
    </div>
@endif
