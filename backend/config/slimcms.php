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

];
