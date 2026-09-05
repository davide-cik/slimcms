<?php

namespace App\Support\Captcha;

/**
 * Google reCAPTCHA v3.
 *
 * v3 e non v2: non chiede niente al visitatore e restituisce un punteggio da
 * 0 a 1. La soglia e' 0.5, il valore che Google stesso indica come
 * ragionevole — piu' alta comincia a rifiutare persone vere, e un modulo che
 * rifiuta chi scrive davvero e' peggio di un po' di spam.
 *
 * Il gettone arriva nel campo `g-recaptcha-response`.
 */
class CaptchaRecaptcha extends CaptchaRemoto
{
    private const SOGLIA = 0.5;

    public function nome(): string
    {
        return 'recaptcha';
    }

    protected function indirizzoVerifica(): string
    {
        return 'https://www.google.com/recaptcha/api/siteverify';
    }

    protected function accettabile(array $corpo): bool
    {
        if (! ($corpo['success'] ?? false)) {
            return false;
        }

        // v2 non manda `score`: se manca, `success` e' gia' la risposta.
        if (! array_key_exists('score', $corpo)) {
            return true;
        }

        return (float) $corpo['score'] >= self::SOGLIA;
    }
}
