import type { APIRoute } from 'astro';

/**
 * Il contatore delle visite, in PHP, sul dominio del sito.
 *
 * Il sito e' statico: una visita non lascia traccia da nessuna parte, e
 * l'unico modo di vederla senza toccare i log del server e' che il visitatore
 * chieda **qualcosa** che sia servito da nostro codice. Questo file e' quel
 * qualcosa: la pagina lo cita come immagine da un pixel, e chiunque scarichi
 * le risorse collegate finisce qui con il proprio user-agent.
 *
 * Un `<img>` e non solo uno script: verificato sul traffico vero di
 * slimcms.it, ClaudeBot scarica logo, font e favicon. Con il solo JavaScript
 * i bot AI sarebbero invisibili; con l'immagine si vedono. Chi esegue anche
 * JavaScript manda un secondo colpo con `js=1`, ed e' cosi' che si distingue
 * una persona da uno scanner che si dichiara Chrome e non esegue mai niente.
 *
 * Come il gestore dei 404: nessuna rete, nessuna credenziale, scrive una riga
 * in una cartella privata fuori dalla document root. Un comando la importa
 * piu' tardi. Se il backend e' fermo, il sito non se ne accorge.
 *
 * Non ci sono cookie e non si salva l'indirizzo IP: l'importazione lo usa per
 * calcolare un'impronta con un sale che cambia ogni giorno, e poi lo butta.
 */
const SORGENTE = `<?php
// Generato da SlimCMS: non modificare a mano, viene riscritto a ogni
// pubblicazione.

// Prima la risposta, poi l'annotazione: il pixel non deve mai rallentare la
// pagina, e qualunque cosa vada storta qui il visitatore non se ne accorge.
header('Content-Type: image/gif');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// GIF trasparente 1x1, la piu' piccola che esista.
$pixel = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
header('Content-Length: ' . strlen($pixel));
echo $pixel;

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

try {
    $percorso = (string) ($_GET['p'] ?? '');

    // Solo un percorso: chi passa un indirizzo assoluto o una stringa lunga
    // sta provando qualcos'altro.
    if ($percorso === '' || $percorso[0] !== '/' || strlen($percorso) > 300) {
        exit;
    }

    // Niente ritorni a capo o caratteri di controllo: una riga per visita, e
    // deve restare una riga sola.
    if (preg_match('/[\\\\x00-\\\\x1f]/', $percorso)) {
        exit;
    }

    $riga = json_encode([
        'p' => $percorso,
        'u' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300),
        'r' => mb_substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 300) ?: null,
        'i' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        // 'v' = una visita; 'j' = solo la conferma che quel client esegue
        // JavaScript. Sono due eventi distinti e non due meta' della stessa
        // cosa: contarli entrambi come visite raddoppierebbe ogni persona,
        // perche' una persona manda tutti e due.
        'e' => ($_GET['e'] ?? '') === 'j' ? 'j' : 'v',
        'q' => gmdate('c'),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $registro = __DIR__ . '/../private/slimcms-viste.jsonl';

    // LOCK_EX perche' piu' visite scrivono insieme: senza, due righe si
    // intrecciano e nessuna delle due e' piu' leggibile. Il tetto e' una
    // difesa: un bot che martella per una notte non deve riempire il disco
    // del cliente.
    if (! is_file($registro) || filesize($registro) < 32 * 1024 * 1024) {
        @file_put_contents($registro, $riga . "\\n", FILE_APPEND | LOCK_EX);
    }
} catch (\\Throwable $e) {
    // volutamente in silenzio: e' un contatore, non una funzione del sito
}
`;

export const GET: APIRoute = async () =>
  new Response(SORGENTE, { headers: { 'Content-Type': 'text/plain; charset=utf-8' } });
