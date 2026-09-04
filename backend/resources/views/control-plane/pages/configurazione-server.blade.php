<x-filament-panels::page>
    <div class="space-y-6">

        {{-- Stato attuale: la prima cosa da sapere è se serve fare qualcosa --}}
        @if ($attiva)
            <x-filament::section>
                <x-slot name="heading">Provisioning automatico attivo</x-slot>
                <p class="text-sm">
                    La regola sudo è installata. Quando crei un sito con un dominio custom,
                    <code>slimcms:provisiona-dominio</code> può creare il vhost e richiedere il
                    certificato senza intervento manuale.
                </p>
            </x-filament::section>
        @else
            <x-filament::section>
                <x-slot name="heading">Provisioning automatico non attivo</x-slot>
                <p class="text-sm">
                    Finché la regola non è installata, il provisioning dei domini custom resta
                    manuale: <code>slimcms:provisiona-dominio &lt;dominio&gt;</code> verifica il DNS
                    e stampa i comandi da eseguire con <code>sudo</code>, invece di eseguirli.
                    Tutto il resto della piattaforma funziona lo stesso.
                </p>
            </x-filament::section>
        @endif

        <x-filament::section>
            <x-slot name="heading">Come installarla</x-slot>
            <x-slot name="description">Da eseguire come root sul server.</x-slot>

            <ol class="list-decimal space-y-3 ps-5 text-sm">
                <li>
                    Verifica la sintassi <strong>prima</strong> di installare. Un file
                    <code>sudoers</code> malformato può rendere <code>sudo</code> inutilizzabile
                    per tutti, root incluso:
                    <pre class="mt-2 overflow-x-auto rounded bg-gray-950 p-3 text-xs text-gray-100">visudo -c -f {{ $percorsoFile }}</pre>
                </li>
                <li>
                    Installalo con proprietario e permessi corretti — <code>sudo</code> ignora i
                    file in <code>/etc/sudoers.d</code> che non siano <code>0440 root:root</code>:
                    <pre class="mt-2 overflow-x-auto rounded bg-gray-950 p-3 text-xs text-gray-100">install -m 0440 -o root -g root \
  {{ $percorsoFile }} \
  /etc/sudoers.d/slimcms-hestia</pre>
                </li>
                <li>
                    Ricarica questa pagina: lo stato in cima si aggiorna da solo.
                </li>
            </ol>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Cosa concede davvero</x-slot>

            <div class="space-y-3 text-sm">
                <p>
                    L'applicazione gira come utente <code>{{ $utente }}</code>. La regola le dà la
                    capacità di creare domini e richiedere certificati <strong>come root</strong>,
                    limitata a due comandi e all'utente Hestia <code>{{ $utente }}</code>.
                    Chi comprometta il codice dell'applicazione ottiene la stessa capacità.
                </p>
                <p>
                    <strong>Non concede:</strong> lettura o scrittura di file arbitrari, esecuzione
                    di altri comandi, gestione di altri utenti del pannello, riavvio di servizi.
                </p>
                <p>
                    Il comando accetta solo domini già presenti fra i siti, quindi non è possibile
                    chiedere un certificato per un dominio non censito qui. Quel vincolo però è nel
                    codice, non in <code>sudoers</code>: se non ti basta, l'alternativa è non
                    installare la regola e provisionare a mano.
                </p>
            </div>
        </x-filament::section>

        <x-filament::section collapsible collapsed>
            <x-slot name="heading">Contenuto del file</x-slot>
            <x-slot name="description">scripts/sudoers/slimcms-hestia, versionato nel repository</x-slot>

            <pre class="overflow-x-auto rounded bg-gray-950 p-4 text-xs leading-relaxed text-gray-100">{{ $contenuto }}</pre>
        </x-filament::section>

    </div>
</x-filament-panels::page>
