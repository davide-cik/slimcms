Tentativi di accesso ripetuti al pannello.

{{ $sospetto['tipo'] === 'ip' ? 'indirizzo' : 'email' }}: {{ $sospetto['valore'] }}
tentativi falliti negli ultimi {{ $minuti }} minuti: {{ $sospetto['quanti'] }}

Ultimi:
@foreach ($ultimi as $riga)
{{ $riga }}
@endforeach

L'elenco completo è in Accessi, nel pannello di gestione.
Questo avviso non si ripete per sei ore sulla stessa chiave.
