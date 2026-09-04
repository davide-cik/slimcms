<?php

namespace Database\Seeders;

/**
 * Contenuto della home di slimcms.it, tenuto in un file a parte perche' e'
 * lungo e cambia con ritmo diverso dalla logica del seeder.
 *
 * Questo file e' la fonte di verita' riproducibile del sito pilota: se il
 * database viene azzerato (i test usano RefreshDatabase), db:seed rimette
 * tutto com'era.
 */
class ContenutoHomeSlimcms
{
    /**
     * Blocchi nel formato del Builder di Filament: tipo e dati separati.
     * Con la forma piatta il builder non li riconosceva e mostrava la pagina
     * vuota, e chi avesse salvato da li' avrebbe cancellato il contenuto.
     */
    public static function blocchi(): array
    {
        return [
            [
                'type' => 'hero',
                'data' => [
                    'occhiello' => 'Piattaforma CMS multitenant',
                    'titolo' => 'Un CMS per chi gestisce venti siti, non uno.',
                    'testo' => 'SlimCMS sostituisce WordPress quando i siti da mantenere sono tanti e piccoli. Un pannello solo per tutti. Pagine pubbliche generate staticamente, servite dalla CDN, che non toccano mai il backend.',
                ],
            ],
            [
                'type' => 'capacita',
                'data' => [
                    'voci' => [
                        [
                            'etichetta' => 'Amministrazione',
                            'titolo' => 'Venti siti, un pannello',
                            'testo' => 'Entri una volta e vedi tutti i siti che gestisci. Nessuna installazione da aggiornare per ognuno, nessun plugin che si rompe su uno e non sull altro.',
                            'macchina' => '"tenant": { "sites": 20, "panels": 1 }',
                        ],
                        [
                            'etichetta' => 'Pubblicazione',
                            'titolo' => 'Il visitatore non aspetta il database',
                            'testo' => 'Quando pubblichi, SlimCMS rigenera solo le pagine cambiate e le manda in CDN. Chi apre il sito riceve un file gia pronto: il backend non viene nemmeno interpellato.',
                            'macchina' => '"render": "static", "origin_hits_per_view": 0',
                        ],
                        [
                            'etichetta' => 'Visibilita',
                            'titolo' => 'Scritto per chi legge, leggibile da chi indicizza',
                            'testo' => 'Oltre ai campi SEO classici, ogni pagina porta una sintesi in linguaggio naturale e un elenco di fatti verificabili: e il formato che i motori generativi citano piu volentieri.',
                            'macchina' => '"structured_summary": true, "key_facts": 4',
                        ],
                    ],
                ],
            ],
            [
                'type' => 'cta',
                'data' => [
                    'titolo' => 'Stiamo costruendo SlimCMS in pubblico.',
                    'etichetta_bottone' => 'ciao@slimcms.it',
                    'url' => 'mailto:ciao@slimcms.it',
                    'testo' => 'La piattaforma e in sviluppo attivo e slimcms.it e il primo sito che gira sopra di essa. Se gestisci molti siti piccoli e la manutenzione ti sta mangiando le giornate, scrivici: cerchiamo i primi utilizzatori.',
                ],
            ],
        ];
    }

    public static function seo(): array
    {
        return [
            'meta_title' => 'SlimCMS — un CMS per chi gestisce venti siti, non uno',
            'meta_description' => 'Piattaforma CMS multitenant: un pannello per tutti i siti, pagine statiche servite da CDN, SEO/GEO/AEO integrati.',
            'canonical_url' => 'https://slimcms.it/',
            'noindex' => false,
            'structured_summary' => 'SlimCMS e\' una piattaforma CMS multitenant che sostituisce WordPress nella gestione di molti mini siti aziendali. Un solo pannello amministra tutti i siti; le pagine pubbliche sono generate staticamente e servite da CDN, senza chiamare il backend.',
            'key_facts' => [
                'Un solo pannello di amministrazione per tutti i siti gestiti.',
                'Le pagine pubbliche sono statiche: nessuna query al database quando un visitatore apre il sito.',
                'Ogni pagina genera automaticamente JSON-LD, sitemap.xml e robots.txt.',
                'L\'isolamento fra i dati di clienti diversi e\' applicato dal database in su, non dal frontend.',
            ],
            'schema_type' => 'SoftwareApplication',
        ];
    }
}
