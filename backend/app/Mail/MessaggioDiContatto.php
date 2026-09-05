<?php

namespace App\Mail;

use App\Models\Messaggio;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * L'avviso al titolare del sito che e' arrivato un messaggio.
 *
 * Il mittente NON e' l'indirizzo del visitatore: una mail che dichiara un
 * mittente di un dominio che non e' il nostro viene scartata da SPF e DKIM,
 * e il titolare non riceve niente. L'indirizzo del visitatore sta nel
 * Reply-To, che e' il posto giusto e fa funzionare "Rispondi".
 */
class MessaggioDiContatto extends Mailable
{
    public function __construct(public readonly Messaggio $messaggio) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Nuovo messaggio dal sito: ' . $this->messaggio->nome,
            replyTo: [new Address($this->messaggio->email, $this->messaggio->nome)],
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.messaggio-di-contatto');
    }
}
