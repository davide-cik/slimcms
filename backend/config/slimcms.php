<?php

return [
    /*
    | IP pubblico del server che serve i siti. I domini custom dei clienti
    | devono puntare qui con un record A perche' Let's Encrypt possa
    | emettere il certificato tramite validazione HTTP.
    */
    'ip_server' => env('SLIMCMS_IP_SERVER', '49.13.157.237'),

    /*
    | Hostname a cui i clienti puntano il proprio dominio con un CNAME.
    |
    | Perche' un CNAME e non l'IP diretto: se un giorno il server cambia
    | indirizzo, si aggiorna un record solo invece di chiedere a ogni cliente
    | di modificare il proprio DNS. E' il motivo per cui tutti i SaaS lo fanno.
    |
    | ATTENZIONE: un CNAME non puo' stare all'apice di una zona (RFC 1034).
    | cliente.it "nudo" NON puo' essere un CNAME: serve un record A, oppure
    | un ALIAS/ANAME/CNAME flattening se il DNS del cliente lo supporta
    | (Cloudflare lo fa). www.cliente.it invece funziona sempre.
    */
    'cname_target' => env('SLIMCMS_CNAME_TARGET', 'sites.slimcms.it'),

    /*
    | Domini dei due pannelli. Lasciandoli vuoti i pannelli rispondono su
    | qualunque host ai percorsi /manage e /admin, che va bene in sviluppo.
    | In produzione vanno valorizzati: il control plane su un host suo rende
    | banale chiuderlo dietro firewall o VPN, cosa impossibile se vive sullo
    | stesso host dei siti dei clienti.
    */
    'dominio_manage' => env('SLIMCMS_DOMINIO_MANAGE'),

    /*
    | Il pannello dei contenuti NON ha un dominio proprio: vive su
    | <dominio-del-sito>/admin, come da specifiche sezione 8. Ogni vhost di
    | sito instrada /admin e /api a PHP; tutto il resto e' servito statico.
    */

    /*
    | Dominio della piattaforma. I siti su un sottodominio di questo sono
    | "nostri" e il certificato lo gestisce il wildcard; gli altri sono
    | domini custom del cliente e richiedono provisioning dedicato.
    */
    'dominio_piattaforma' => env('SLIMCMS_DOMINIO_PIATTAFORMA', 'slimcms.it'),

    /*
    | Utente del pannello di hosting sotto cui vivono i vhost.
    */
    'utente_hosting' => env('SLIMCMS_UTENTE_HOSTING', 'claudio'),

    /*
    | Destinatario degli alert su certificati e build.
    */
    'email_alert' => env('SLIMCMS_EMAIL_ALERT', 'davide@giansoldati.it'),

    /*
    | Script che ricostruisce e pubblica il frontend.
    |
    | Non e' un percorso relativo all'applicazione: in produzione l'app vive
    | dentro <dominio>/private/ mentre gli script stanno nel repository, che e'
    | altrove. Con un percorso relativo la coda di build fallisce con "script
    | di deploy non trovato" senza che nulla spieghi perche'.
    */
    'script_deploy' => env('SLIMCMS_SCRIPT_DEPLOY', '/home/claudio/dev/slimcms/scripts/deploy-frontend.sh'),

    /*
    |--------------------------------------------------------------------------
    | Registro dei 404
    |--------------------------------------------------------------------------
    |
    | Dove il gestore d'errore di ogni sito annota gli indirizzi mancanti.
    | E' un MODELLO di percorso, non un percorso: {dominio} viene sostituito
    | col dominio del sito. Sta in `private/`, che non e' servita dal web.
    |
    | Se un sito vivesse altrove, si cambia il modello: il gestore lo scrive
    | sempre accanto alla propria document root, quindi il modello e la
    | struttura delle cartelle devono corrispondere.
    |
    */

    'registro_404' => env(
        'SLIMCMS_REGISTRO_404',
        '/home/' . env('SLIMCMS_UTENTE_HOSTING', 'claudio') . '/web/{dominio}/private/slimcms-404.jsonl'
    ),

    /*
    | Dove il contatore delle visite annota, sul dominio di ogni sito. Stessa
    | cartella privata del registro dei 404: fuori dalla document root, quindi
    | non leggibile dal web e non cancellata da `rsync --delete`.
    */
    'registro_viste' => env(
        'SLIMCMS_REGISTRO_VISTE',
        '/home/' . env('SLIMCMS_UTENTE_HOSTING', 'claudio') . '/web/{dominio}/private/slimcms-viste.jsonl'
    ),

    /*
    |--------------------------------------------------------------------------
    | Riconoscimento degli agenti
    |--------------------------------------------------------------------------
    |
    | Le firme cercate dentro lo user-agent, in minuscolo. Stanno qui e non
    | nel codice perche' "altri bot e scanner" e' una lista che cresce ogni
    | mese: aggiungerne una non deve voler dire toccare una classe.
    |
    | L'ordine in cui vengono provate lo decide ClassificatoreAgente: prima
    | ai, poi motore, poi bot, e solo alla fine "sembra un browser". Quasi
    | tutti i bot si dichiarano Mozilla/5.0 per non essere bloccati.
    */
    'agenti' => [

        // I crawler dei modelli generativi. Distinguerli dai motori non e'
        // pedanteria: chi scrive contenuti vuole sapere se le sue pagine
        // finiscono in un indice o in un corpus di addestramento.
        'ai' => [
            'gptbot', 'oai-searchbot', 'chatgpt-user', 'claudebot', 'claude-web',
            'anthropic-ai', 'perplexitybot', 'perplexity-user', 'ccbot',
            'google-extended', 'bytespider', 'amazonbot', 'meta-externalagent',
            'applebot-extended', 'cohere-ai', 'diffbot', 'imagesiftbot',
            'youbot', 'timpibot', 'omgili', 'facebookbot',
        ],

        // I motori di ricerca veri e propri, quelli che portano visite.
        'motore' => [
            'googlebot', 'bingbot', 'slurp', 'duckduckbot', 'baiduspider',
            'yandexbot', 'applebot', 'sogou', 'exabot', 'seznambot',
            'petalbot', 'mojeekbot', 'qwantify',
        ],

        // Tutto il resto che non e' una persona: strumenti, librerie,
        // monitoraggi, scanner di vulnerabilita', anteprime dei social.
        'bot' => [
            'curl/', 'wget', 'python-requests', 'python-urllib', 'go-http-client',
            'java/', 'okhttp', 'axios', 'node-fetch', 'guzzlehttp', 'libwww-perl',
            'headlesschrome', 'phantomjs', 'puppeteer', 'playwright',
            'l9scan', 'leakix', 'zgrab', 'masscan', 'nmap', 'nuclei', 'sqlmap',
            'wpscan', 'wp-scanner', 'censys', 'shodan', 'internetmeasurement',
            'ahrefsbot', 'semrushbot', 'mj12bot', 'dotbot', 'blexbot',
            'dataforseobot', 'serpstatbot', 'barkrowler',
            'uptimerobot', 'pingdom', 'statuscake', 'site24x7',
            'twitterbot', 'linkedinbot', 'whatsapp', 'telegrambot', 'slackbot',
            'discordbot', 'embedly', 'skypeuripreview', 'bot', 'crawler',
            'spider', 'scraper',
        ],

        // Da user-agent a nome leggibile. La prima firma che corrisponde
        // vince, quindi le piu' specifiche vanno prima: "Edg/" contiene
        // "Chrome" nel proprio user-agent.
        'nomi' => [
            'GPTBot' => 'GPTBot (OpenAI)',
            'OAI-SearchBot' => 'OAI-SearchBot (OpenAI)',
            'ChatGPT-User' => 'ChatGPT (browsing)',
            'ClaudeBot' => 'ClaudeBot (Anthropic)',
            'anthropic-ai' => 'Anthropic',
            'PerplexityBot' => 'PerplexityBot',
            'CCBot' => 'CCBot (Common Crawl)',
            'Google-Extended' => 'Google-Extended',
            'Bytespider' => 'Bytespider (ByteDance)',
            'Amazonbot' => 'Amazonbot',
            'Meta-ExternalAgent' => 'Meta',
            'Googlebot' => 'Googlebot',
            'bingbot' => 'Bingbot',
            'DuckDuckBot' => 'DuckDuckBot',
            'YandexBot' => 'YandexBot',
            'Applebot' => 'Applebot',
            'AhrefsBot' => 'AhrefsBot',
            'SemrushBot' => 'SemrushBot',
            'l9scan' => 'LeakIX (scanner)',
            'CT-WP-Scanner' => 'Scanner WordPress',
            'curl/' => 'curl',
            'Wget' => 'wget',
            'python-requests' => 'python-requests',
            'Go-http-client' => 'Go http client',
            'Edg/' => 'Edge',
            'OPR/' => 'Opera',
            'Chrome/' => 'Chrome',
            'Firefox/' => 'Firefox',
            'Safari/' => 'Safari',
        ],
    ],

];
