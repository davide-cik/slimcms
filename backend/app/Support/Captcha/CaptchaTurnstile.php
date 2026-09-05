<?php

namespace App\Support\Captcha;

/**
 * Cloudflare Turnstile.
 *
 * E' il captcha attuale di Cloudflare (ha sostituito hCaptcha nel loro
 * stack): quasi sempre invisibile, senza selezione di semafori, e senza
 * spedire dati a chi profila.
 *
 * Il gettone arriva nel campo `cf-turnstile-response`.
 */
class CaptchaTurnstile extends CaptchaRemoto
{
    public function nome(): string
    {
        return 'turnstile';
    }

    protected function indirizzoVerifica(): string
    {
        return 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    }

    protected function accettabile(array $corpo): bool
    {
        return (bool) ($corpo['success'] ?? false);
    }
}
