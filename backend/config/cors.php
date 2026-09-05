<?php

/**
 * CORS solo per gli endpoint pubblici.
 *
 * Il sito statico vive su <dominio-cliente> e l'API su manage.slimcms.it:
 * la chiamata del visitatore e' per forza cross-origin. Senza questo il
 * browser blocca la risposta e il form di contatto non funziona da nessun
 * sito.
 *
 * L'origine e' aperta di proposito. Non e' una scorciatoia: qui non
 * viaggiano ne' cookie ne' token (`supports_credentials` false), quindi non
 * c'e' nessuna sessione che un'altra origine possa sfruttare, e mandare un
 * messaggio dal form e' comunque una cosa che chiunque puo' fare con `curl`.
 * Restringere l'elenco darebbe l'impressione di una difesa che non c'e' — la
 * difesa e' il rate limiting e l'honeypot.
 *
 * `paths` limita tutto questo a `api/public/*`: il resto dell'API (token di
 * build, control plane) non risponde a nessuna origine.
 */
return [
    'paths' => ['api/public/*'],
    'allowed_methods' => ['GET', 'POST', 'OPTIONS'],
    'allowed_origins' => ['*'],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Content-Type', 'Accept'],
    'exposed_headers' => [],
    'max_age' => 3600,
    'supports_credentials' => false,
];
