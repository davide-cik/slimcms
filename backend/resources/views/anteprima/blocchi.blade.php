{{--
    Il rendering dei blocchi per l'anteprima nel pannello.

    E' un SECONDO renderer: quello vero e' `frontend/src/components/blocchi/
    Blocchi.astro`, e Astro non gira in una richiesta PHP. Due meta' scritte
    in momenti diversi sono la giuntura da cui nasce quasi ogni guasto di
    questo progetto, quindi `ContrattoBlocchiTest` rende ogni tipo di blocco
    anche da qui e fallisce se uno non produce niente.

    Le classi sono le stesse del sito perche' l'anteprima carica i FOGLI DI
    STILE VERI del sito pubblicato: non c'e' una copia del CSS da tenere
    allineata.

    Due scostamenti voluti, dichiarati anche a schermo:
    - `incorpora` non produce un iframe. Il controllo su quali domini si
      possono incorporare e' logica di sicurezza, e riprodurla qui vorrebbe
      dire tenerne due copie allineate: la peggior duplicazione possibile.
    - `modulo_contatto` e' inerte: un'anteprima non deve poter mandare un
      messaggio vero.
--}}
@php
    /** L'immagine risolta dall'API: url assoluta verso il backend, piu' l'alt. */
    $eMedia = fn ($v) => is_array($v) && isset($v['url']);
    $immagini = fn ($v) => collect(is_array($v) ? $v : [])->filter($eMedia);
@endphp

@foreach ($blocchi as $blocco)
    @php
        $tipo = $blocco['type'] ?? null;
        $b = $blocco['data'] ?? [];
    @endphp

    @switch($tipo)
        @case('hero')
            <section class="apertura">
                @if (filled($b['occhiello'] ?? null))<p class="occhiello">{{ $b['occhiello'] }}</p>@endif
                <h1>{!! $b['titolo'] ?? '' !!}</h1>
                @if (filled($b['testo'] ?? null))<p class="sommario">{{ $b['testo'] }}</p>@endif
            </section>
            @break

        @case('testo_ricco')
            <section class="chiusura">
                <div class="corpo-ricco">{!! $b['corpo'] ?? '' !!}</div>
            </section>
            @break

        @case('cta')
            <section class="chiusura">
                <h2>{{ $b['titolo'] ?? '' }}</h2>
                @if (filled($b['testo'] ?? null))<p>{{ $b['testo'] }}</p>@endif
                <a class="bottone primario" href="{{ $b['url'] ?? '#' }}">{{ $b['etichetta_bottone'] ?? '' }}</a>
            </section>
            @break

        @case('galleria')
            <section class="capacita">
                @if (filled($b['titolo'] ?? null))<h2>{{ $b['titolo'] }}</h2>@endif
                <div class="galleria">
                    @foreach ($immagini($b['media'] ?? []) as $i)
                        <img src="{{ $i['url'] }}" alt="{{ $i['alt'] ?? '' }}" loading="lazy">
                    @endforeach
                </div>
            </section>
            @break

        @case('immagine_testo')
            @php $foto = $eMedia($b['media'] ?? null) ? $b['media'] : null; @endphp
            <section class="capacita accostato{{ ($b['posizione'] ?? '') === 'destra' ? ' invertito' : '' }}">
                @if ($foto)<img src="{{ $foto['url'] }}" alt="{{ $foto['alt'] ?? '' }}" loading="lazy">@endif
                <div>
                    @if (filled($b['titolo'] ?? null))<h2>{{ $b['titolo'] }}</h2>@endif
                    @if (filled($b['corpo'] ?? null))<div class="corpo-ricco">{!! $b['corpo'] !!}</div>@endif
                </div>
            </section>
            @break

        @case('citazione')
            <section class="chiusura">
                <figure class="citazione">
                    <blockquote>{{ $b['testo'] ?? '' }}</blockquote>
                    @if (filled($b['autore'] ?? null) || filled($b['ruolo'] ?? null))
                        <figcaption>
                            @if (filled($b['autore'] ?? null))<strong>{{ $b['autore'] }}</strong>@endif
                            @if (filled($b['autore'] ?? null) && filled($b['ruolo'] ?? null)) — @endif
                            {{ $b['ruolo'] ?? '' }}
                        </figcaption>
                    @endif
                </figure>
            </section>
            @break

        @case('numeri')
            <section class="capacita">
                <dl class="numeri">
                    @foreach ($b['voci'] ?? [] as $v)
                        <div>
                            <dt>{{ $v['etichetta'] ?? '' }}</dt>
                            <dd>{{ $v['valore'] ?? '' }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>
            @break

        @case('loghi')
            <section class="capacita">
                @if (filled($b['titolo'] ?? null))<h2>{{ $b['titolo'] }}</h2>@endif
                <ul class="loghi">
                    @foreach ($immagini($b['media'] ?? []) as $i)
                        <li><img src="{{ $i['url'] }}" alt="{{ $i['alt'] ?? '' }}" loading="lazy"></li>
                    @endforeach
                </ul>
            </section>
            @break

        @case('incorpora')
            {{-- Segnaposto e non un iframe: vedi la nota in testa al file. --}}
            <section class="chiusura">
                <div class="anteprima-segnaposto">
                    <strong>{{ $b['titolo'] ?? 'Contenuto incorporato' }}</strong>
                    <span>{{ $b['url'] ?? '' }}</span>
                    <em>Video e mappe si vedono solo sul sito pubblicato.</em>
                </div>
            </section>
            @break

        @case('contatti')
            <section class="chiusura">
                @if (filled($b['titolo'] ?? null))<h2>{{ $b['titolo'] }}</h2>@endif
                <ul class="contatti">
                    @if (filled($b['indirizzo'] ?? null))<li>{{ $b['indirizzo'] }}</li>@endif
                    @if (filled($b['telefono'] ?? null))
                        <li><a href="tel:{{ preg_replace('/[^+\d]/', '', $b['telefono']) }}">{{ $b['telefono'] }}</a></li>
                    @endif
                    @if (filled($b['email'] ?? null))<li><a href="mailto:{{ $b['email'] }}">{{ $b['email'] }}</a></li>@endif
                    @if (filled($b['orari'] ?? null))<li>{{ $b['orari'] }}</li>@endif
                </ul>
            </section>
            @break

        @case('modulo_contatto')
            <section class="modulo-contatto">
                @if (filled($b['titolo'] ?? null))<h2>{{ $b['titolo'] }}</h2>@endif
                @if (filled($b['testo'] ?? null))<p class="modulo-intro">{{ $b['testo'] }}</p>@endif
                {{-- Inerte: un'anteprima non deve poter mandare un messaggio vero. --}}
                <form onsubmit="return false">
                    <div class="campo"><label>Nome</label><input type="text" disabled></div>
                    <div class="campo"><label>Email</label><input type="email" disabled></div>
                    <div class="campo"><label>Messaggio</label><textarea rows="4" disabled></textarea></div>
                    <button type="button" disabled>{{ $b['etichetta'] ?? 'Invia il messaggio' }}</button>
                </form>
                <p class="anteprima-nota">Il modulo funziona sul sito pubblicato.</p>
            </section>
            @break

        @case('separatore')
            @if (($b['stile'] ?? 'linea') === 'spazio')
                <div class="separatore-spazio"></div>
            @else
                <hr class="separatore">
            @endif
            @break

        @case('capacita')
            <section class="capacita" id="capacita">
                @foreach ($b['voci'] ?? [] as $c)
                    <article class="capacita-riga">
                        <span class="etichetta">{{ $c['etichetta'] ?? '' }}</span>
                        <div class="capacita-corpo">
                            <h2>{{ $c['titolo'] ?? '' }}</h2>
                            <p>{{ $c['testo'] ?? '' }}</p>
                        </div>
                        @if (filled($c['macchina'] ?? null))<code class="capacita-macchina">{{ $c['macchina'] }}</code>@endif
                    </article>
                @endforeach
            </section>
            @break

        @case('faq')
            <section class="capacita">
                @foreach ($b['voci'] ?? [] as $v)
                    <details class="faq-voce">
                        <summary>{{ $v['domanda'] ?? '' }}</summary>
                        <p>{{ $v['risposta'] ?? '' }}</p>
                    </details>
                @endforeach
            </section>
            @break

        @default
            {{-- Un tipo che l'anteprima non conosce si dice, non si salta: il
                 sito pubblico lo ignora in silenzio, ma qui il redattore sta
                 guardando proprio per sapere cosa vedra'. --}}
            <section class="chiusura">
                <div class="anteprima-segnaposto">
                    <strong>Blocco «{{ $tipo }}»</strong>
                    <em>L'anteprima non sa ancora disegnarlo. Sul sito potrebbe comparire lo stesso.</em>
                </div>
            </section>
    @endswitch
@endforeach
