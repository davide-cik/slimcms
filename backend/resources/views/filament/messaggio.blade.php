<div class="fi-prose">
    <p style="white-space: pre-wrap">{{ $messaggio->messaggio }}</p>

    @if ($messaggio->pagina)
        <p style="opacity:.7;font-size:.875rem">Inviato dalla pagina <code>{{ $messaggio->pagina }}</code></p>
    @endif
</div>
