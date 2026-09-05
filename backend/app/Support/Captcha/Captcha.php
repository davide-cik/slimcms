<?php

namespace App\Support\Captcha;

/**
 * Un fornitore di captcha.
 *
 * L'interfaccia esiste perche' il fornitore lo sceglie ogni sito: chi non ha
 * un account Cloudflare o Google deve avere comunque qualcosa che funzioni,
 * e chi ce l'ha non deve essere costretto al nostro.
 *
 * `sfida()` serve solo a chi la deve generare lato server (il captcha
 * semplice); Turnstile e reCAPTCHA la generano nel browser e qui rispondono
 * con niente.
 */
interface Captcha
{
    /** Il nome con cui il sito lo dichiara nella configurazione. */
    public function nome(): string;

    /**
     * Cosa serve al sito statico per disegnare il captcha: il tipo e, dove
     * serve, la chiave pubblica. Nessun segreto puo' comparire qui: finisce
     * nell'HTML di una pagina pubblica.
     *
     * @return array<string, mixed>
     */
    public function perIlSito(): array;

    /**
     * Una sfida nuova, per i captcha che la generano lato server.
     *
     * @return array<string, mixed>|null
     */
    public function sfida(): ?array;

    /** La risposta del visitatore e' valida? */
    public function verifica(?string $risposta, ?string $token, ?string $ip = null): bool;
}
