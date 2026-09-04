<?php

return [
    /*
    | IP pubblico del server che serve i siti. I domini custom dei clienti
    | devono puntare qui con un record A perche' Let's Encrypt possa
    | emettere il certificato tramite validazione HTTP.
    */
    'ip_server' => env('SLIMCMS_IP_SERVER', '49.13.157.237'),

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
];
