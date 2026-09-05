<?php

namespace App\Support\Captcha;

use Illuminate\Support\Facades\Cache;

/**
 * Una domanda di aritmetica, firmata.
 *
 * Serve a chi non ha — e non vuole — un account Cloudflare o Google. Non
 * ferma un attacco mirato, e non e' il suo mestiere: ferma i bot che
 * compilano moduli a tappeto, che sono quasi tutti.
 *
 * **Nessuno stato sul server.** La sfida viaggia come `domanda` piu' un
 * token che e' `scadenza.firma`, dove la firma e' un HMAC della risposta
 * giusta. Alla verifica si ricalcola l'HMAC con la risposta arrivata: se
 * combacia, era quella giusta. Cosi' non serve una tabella di sfide aperte,
 * che sarebbe uno stato da far scadere e da pulire — e un posto in cui un
 * bot puo' far crescere righe a piacere.
 *
 * Il token speso finisce in cache fino alla sua scadenza: senza, la stessa
 * coppia domanda/risposta si potrebbe rigiocare finche' non scade.
 */
class CaptchaSemplice implements Captcha
{
    private const VALIDITA = 900; // 15 minuti: il tempo di scrivere un messaggio

    public function nome(): string
    {
        return 'semplice';
    }

    public function perIlSito(): array
    {
        return ['tipo' => 'semplice'];
    }

    public function sfida(): ?array
    {
        // Numeri piccoli e somma o differenza: dev'essere una domanda che
        // chiunque risolve a mente, non un test di matematica.
        $a = random_int(2, 9);
        $b = random_int(1, $a - 1);
        $piu = (bool) random_int(0, 1);

        $risposta = $piu ? $a + $b : $a - $b;
        $scadenza = time() + self::VALIDITA;

        return [
            'domanda' => sprintf('Quanto fa %d %s %d?', $a, $piu ? '+' : '−', $b),
            'token' => $scadenza . '.' . $this->firma((string) $risposta, $scadenza),
        ];
    }

    public function verifica(?string $risposta, ?string $token, ?string $ip = null): bool
    {
        if (blank($risposta) || blank($token) || ! str_contains($token, '.')) {
            return false;
        }

        [$scadenza, $firma] = explode('.', $token, 2);

        if (! ctype_digit($scadenza) || (int) $scadenza < time()) {
            return false;
        }

        $attesa = $this->firma(trim($risposta), (int) $scadenza);

        // hash_equals e non ==: il confronto di una firma dev'essere a tempo
        // costante.
        if (! hash_equals($attesa, $firma)) {
            return false;
        }

        // Un token gia' speso non vale una seconda volta.
        $chiave = 'captcha:speso:' . hash('sha256', $token);

        if (Cache::has($chiave)) {
            return false;
        }

        Cache::put($chiave, true, max(1, (int) $scadenza - time()));

        return true;
    }

    private function firma(string $risposta, int $scadenza): string
    {
        return hash_hmac('sha256', $risposta . '|' . $scadenza, (string) config('app.key'));
    }
}
