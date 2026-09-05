<x-mail::message>
# Nuovo messaggio dal sito

**Da:** {{ $messaggio->nome }} ({{ $messaggio->email }})
@if ($messaggio->pagina)
**Pagina:** {{ $messaggio->pagina }}
@endif
**Ricevuto:** {{ $messaggio->created_at?->format('d/m/Y H:i') }}

---

{{ $messaggio->messaggio }}

---

Il messaggio è salvato anche nel pannello, quindi non si perde nemmeno se
questa email non arriva a destinazione.
</x-mail::message>
