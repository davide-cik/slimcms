<?php

namespace App\Support\Captcha;

use App\Models\Site;

/**
 * Il captcha di un sito, dalle sue impostazioni.
 *
 * Il valore predefinito e' quello semplice e non "nessuno": un modulo appena
 * creato deve gia' avere una difesa, e quella semplice non chiede un account
 * a nessuno. Chi vuole toglierla lo dichiara.
 */
class FabbricaCaptcha
{
    public const FORNITORI = [
        'semplice' => 'Domanda semplice (nessun account, nessun dato a terzi)',
        'turnstile' => 'Cloudflare Turnstile',
        'recaptcha' => 'Google reCAPTCHA v3',
        'nessuno' => 'Nessuno (solo esca e limite di invii)',
    ];

    public static function per(?Site $site): Captcha
    {
        return match ($site?->captcha_fornitore) {
            'turnstile' => new CaptchaTurnstile($site->captcha_chiave_pubblica, $site->captcha_segreto),
            'recaptcha' => new CaptchaRecaptcha($site->captcha_chiave_pubblica, $site->captcha_segreto),
            'nessuno' => new CaptchaAssente(),
            default => new CaptchaSemplice(),
        };
    }
}
