<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * L'avviso su tentativi di accesso ripetuti.
 *
 * Una Mailable e non `Mail::raw`: un invio grezzo non e' osservabile nei
 * test — `Mail::fake()` conta le Mailable — e un allarme che nessuno puo'
 * verificare e' un allarme di cui non ci si puo' fidare.
 */
class AvvisoAccessi extends Mailable
{
    /**
     * @param  array{tipo: string, valore: string, quanti: int}  $sospetto
     * @param  list<string>  $ultimi
     */
    public function __construct(
        public readonly array $sospetto,
        public readonly int $minuti,
        public readonly array $ultimi,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "SlimCMS: tentativi di accesso da {$this->sospetto['valore']}");
    }

    public function content(): Content
    {
        return new Content(text: 'mail.avviso-accessi');
    }
}
