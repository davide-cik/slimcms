<div class="fi-prose">
    <p style="white-space: pre-wrap">{{ $messaggio->messaggio }}</p>

    @if (filled($messaggio->dati))
        <dl style="display:grid;grid-template-columns:auto 1fr;gap:.25rem 1rem;margin:1rem 0">
            @foreach ($messaggio->dati as $campo)
                <dt style="font-weight:600">{{ $campo['etichetta'] ?? '' }}</dt>
                <dd style="margin:0">{{ is_bool($campo['valore'] ?? null) ? (($campo['valore']) ? 'sì' : 'no') : ($campo['valore'] ?? '—') }}</dd>
            @endforeach
        </dl>
    @endif

    @if ($messaggio->pagina)
        <p style="opacity:.7;font-size:.875rem">Inviato dalla pagina <code>{{ $messaggio->pagina }}</code></p>
    @endif
</div>
