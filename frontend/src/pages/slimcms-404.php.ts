import type { APIRoute } from 'astro';

/**
 * Il gestore della pagina d'errore, in PHP, sul dominio del sito.
 *
 * Apache lo invoca come ErrorDocument 404 e gli passa l'indirizzo richiesto.
 * Lui annota una riga in una cartella privata — fuori dalla document root,
 * quindi non leggibile dal web — e stampa la pagina 404 gia' costruita.
 *
 * Non chiama nessuna rete e non conosce nessuna credenziale: e' la ragione
 * per cui il percorso d'errore resta veloce e continua a funzionare anche se
 * il backend e' fermo. Un comando importa quelle righe piu' tardi.
 *
 * Sta qui e non nella document root a mano perche' la pubblicazione fa
 * `rsync --delete`: un file messo li' sparirebbe al primo deploy.
 */
const SORGENTE = `<?php
// Generato da SlimCMS: non modificare a mano, viene riscritto a ogni
// pubblicazione.

http_response_code(404);

// L'annotazione non deve mai impedire alla pagina di comparire: qualunque
// cosa vada storta qui, il visitatore vede comunque il 404 del sito.
try {
    $percorso = $_SERVER['REDIRECT_URL'] ?? $_SERVER['REQUEST_URI'] ?? '';
    $percorso = parse_url((string) $percorso, PHP_URL_PATH) ?: '';

    // Un indirizzo lunghissimo e' un tentativo di iniezione, non un
    // collegamento rotto.
    if ($percorso !== '' && strlen($percorso) <= 500) {
        $riga = json_encode([
            'p' => $percorso,
            'r' => mb_substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 500) ?: null,
            'q' => gmdate('c'),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $registro = __DIR__ . '/../private/slimcms-404.jsonl';

        // LOCK_EX perche' piu' richieste possono scrivere insieme: senza,
        // due righe si intrecciano e nessuna delle due e' piu' leggibile.
        // Il tetto e' una difesa: uno scanner che tira a indovinare per una
        // notte intera non deve riempire il disco del cliente.
        if (! is_file($registro) || filesize($registro) < 8 * 1024 * 1024) {
            @file_put_contents($registro, $riga . "\\n", FILE_APPEND | LOCK_EX);
        }
    }
} catch (\\Throwable $e) {
    // volutamente in silenzio
}

$pagina = __DIR__ . '/404.html';

if (is_file($pagina)) {
    header('Content-Type: text/html; charset=utf-8');
    readfile($pagina);
} else {
    echo 'Pagina non trovata.';
}
`;

export const GET: APIRoute = async () =>
  new Response(SORGENTE, { headers: { 'Content-Type': 'text/plain; charset=utf-8' } });
