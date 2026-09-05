<?php

namespace App\Support\Captcha;

/**
 * Nessun captcha.
 *
 * Resta una scelta legittima: su un sito con pochissimo traffico l'esca e il
 * rate limit bastano, e un captcha in piu' e' attrito per chi scrive davvero.
 * Non e' pero' il valore predefinito: quello e' il captcha semplice, che non
 * chiede nessun account a nessuno.
 */
class CaptchaAssente implements Captcha
{
    public function nome(): string
    {
        return 'nessuno';
    }

    public function perIlSito(): array
    {
        return ['tipo' => 'nessuno'];
    }

    public function sfida(): ?array
    {
        return null;
    }

    public function verifica(?string $risposta, ?string $token, ?string $ip = null): bool
    {
        return true;
    }
}
