<?php

namespace App\Support\Captcha;

use Illuminate\Support\Facades\Http;

/**
 * Base dei captcha verificati da un servizio esterno.
 *
 * Turnstile e reCAPTCHA funzionano allo stesso modo: il browser ottiene un
 * gettone, il server lo manda al fornitore insieme al proprio segreto, e il
 * fornitore dice se vale. Cambia l'indirizzo e come si legge la risposta.
 *
 * **Un fornitore irraggiungibile non deve buttare via un messaggio.** Se la
 * verifica non si puo' fare — rete giu', servizio in errore — si accetta e si
 * annota: un modulo che smette di funzionare perche' Cloudflare ha un
 * problema fa perdere richieste vere, e il rate limit con l'esca restano in
 * piedi comunque.
 */
abstract class CaptchaRemoto implements Captcha
{
    public function __construct(
        protected readonly ?string $chiavePubblica,
        protected readonly ?string $segreto,
    ) {}

    abstract protected function indirizzoVerifica(): string;

    /** @param array<string, mixed> $corpo */
    abstract protected function accettabile(array $corpo): bool;

    public function perIlSito(): array
    {
        return ['tipo' => $this->nome(), 'chiave' => $this->chiavePubblica];
    }

    public function sfida(): ?array
    {
        // La genera il browser parlando col fornitore.
        return null;
    }

    public function verifica(?string $risposta, ?string $token, ?string $ip = null): bool
    {
        // Senza gettone non c'e' niente da verificare: e' un modulo inviato
        // senza passare dal captcha.
        if (blank($token)) {
            return false;
        }

        if (blank($this->segreto)) {
            logger()->warning('Captcha configurato senza segreto', ['fornitore' => $this->nome()]);

            return false;
        }

        try {
            $risposta = Http::asForm()->timeout(5)->post($this->indirizzoVerifica(), array_filter([
                'secret' => $this->segreto,
                'response' => $token,
                'remoteip' => $ip,
            ]));
        } catch (\Throwable $e) {
            logger()->warning('Captcha non verificabile, messaggio accettato', [
                'fornitore' => $this->nome(),
                'errore' => $e->getMessage(),
            ]);

            return true;
        }

        if (! $risposta->successful()) {
            logger()->warning('Captcha non verificabile, messaggio accettato', [
                'fornitore' => $this->nome(),
                'stato' => $risposta->status(),
            ]);

            return true;
        }

        return $this->accettabile($risposta->json() ?? []);
    }
}
