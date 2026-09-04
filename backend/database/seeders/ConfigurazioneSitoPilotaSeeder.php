<?php

namespace Database\Seeders;

use App\Models\Site;
use Illuminate\Database\Seeder;

/**
 * La configurazione di aspetto del sito pilota: tema, testata, footer, SEO.
 *
 * Sta in un seeder suo e non dentro SlimcmsPilotSeeder perche' quello crea
 * anche pagine e articoli con updateOrCreate: lanciarlo in produzione
 * riscriverebbe contenuti gia' modificati dal pannello. Questo invece tocca
 * solo i campi ANCORA VUOTI di un sito che esiste gia', quindi si puo'
 * eseguire in produzione dopo un deploy che aggiunge una colonna nuova.
 */
class ConfigurazioneSitoPilotaSeeder extends Seeder
{
    /**
     * I valori di partenza, condivisi con SlimcmsPilotSeeder cosi' che non
     * possano divergere fra sviluppo e produzione.
     *
     * @return array<string, mixed>
     */
    public static function predefiniti(): array
    {
        return [
            'theme' => [
                'carta' => '#f4f4f1',
                'inchiostro' => '#16181c',
                'segnale' => '#0f6b4a',
            ],
            'seo_defaults' => [
                'og_image' => '/logo.svg',
                'publisher' => 'Content is King Srl',
            ],
            // Testata e footer prima stavano cablati nel layout Astro:
            // da qui in poi si cambiano dal pannello, senza toccare il
            // codice e senza un deploy.
            'layout_config' => [
                'tipo' => 'semplice',
                'mostra_logo' => true,
                'nome_visibile' => 'SlimCMS',
                'voci' => [
                    ['etichetta' => 'Cosa fa', 'url' => '/#capacita', 'evidenza' => false],
                    ['etichetta' => 'Per le macchine', 'url' => '/#macchina', 'evidenza' => false],
                    ['etichetta' => 'Parlane con noi', 'url' => 'mailto:ciao@slimcms.it', 'evidenza' => true],
                ],
                'doppio' => [
                    'attivo' => true,
                    'etichetta' => 'Questa pagina, due volte',
                    'testo' => 'Ogni pagina fatta con SlimCMS esiste in due versioni contemporaneamente. Quella che stai leggendo, e quella qui sotto: la sola che vedono Google, Perplexity e ChatGPT. La seconda non e un ripensamento, si scrive insieme alla prima.',
                ],
            ],
            // Il footer che prima stava cablato nel layout Astro: da qui
            // in poi si cambia dal pannello, senza toccare il codice.
            'footer_config' => [
                'tipo' => 'colonne',
                'colonne' => 3,
                'blocchi' => [
                    [
                        'titolo' => 'Prodotto',
                        'voci' => [
                            ['etichetta' => 'Cosa fa', 'url' => '/#capacita'],
                            ['etichetta' => 'Per le macchine', 'url' => '/#macchina'],
                        ],
                    ],
                    [
                        'titolo' => 'Azienda',
                        'voci' => [
                            ['etichetta' => 'Chi siamo', 'url' => '/chi-siamo/'],
                        ],
                    ],
                    [
                        'titolo' => 'Contatti',
                        'voci' => [
                            ['etichetta' => 'ciao@slimcms.it', 'url' => 'mailto:ciao@slimcms.it'],
                        ],
                    ],
                ],
                'descrizione' => 'piattaforma CMS multitenant',
                'firma' => true,
                'organizzazione' => 'Content is King Srl',
                'legale' => '© 2026 SlimCMS · Content is King Srl · via Carducci 8, 20814 Varedo (MB) · P.IVA IT11732600967',
            ]
        ];
    }

    public function run(): void
    {
        $site = Site::withoutTenancy()->where('domain', 'slimcms.it')->first();

        if ($site === null) {
            $this->command?->warn('slimcms.it non esiste ancora: niente da configurare.');

            return;
        }

        $applicati = [];

        foreach (self::predefiniti() as $campo => $valore) {
            // Solo i campi vuoti: cio' che e' stato cambiato dal pannello
            // resta com'e'. Un seeder che rigira non deve disfare il lavoro
            // di chi usa il prodotto.
            if (blank($site->{$campo})) {
                $site->{$campo} = $valore;
                $applicati[] = $campo;
            }
        }

        if ($applicati !== []) {
            $site->saveQuietly();
        }

        $this->command?->info($applicati === []
            ? 'Configurazione del sito gia\' presente: nulla da fare.'
            : 'Configurazione applicata: ' . implode(', ', $applicati) . '.');
    }
}
