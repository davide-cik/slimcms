<?php

namespace Database\Seeders;

use App\Models\Page;
use App\Models\Plan;
use App\Models\Site;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * Primo sito pilota: slimcms.it servito dalla piattaforma stessa.
 *
 * Nota sull'ordine: le Page vanno create DOPO $site->useAsCurrent(),
 * altrimenti BelongsToSite solleva un'eccezione. E' voluto: in contesto
 * console non c'e' nessun middleware che risolva il sito dal dominio, e
 * una riga con site_id NULL sarebbe invisibile a ogni query scoped.
 */
class SlimcmsPilotSeeder extends Seeder
{
    public function run(): void
    {
        $plan = Plan::firstOrCreate(
            ['name' => 'Interno'],
            [
                'price_monthly' => 0,
                'max_sites' => 100,
                'max_storage_gb' => 50,
                'features_included' => ['seo', 'geo', 'aeo', 'blog', 'form'],
            ]
        );

        $tenant = Tenant::firstOrCreate(
            ['slug' => 'content-is-king'],
            [
                'id' => 'content-is-king',
                'name' => 'Content is King Srl',
                'status' => 'active',
                'plan_id' => $plan->id,
            ]
        );

        // Site usa il trait di stancl: senza tenancy inizializzata lo scope e'
        // inerte e tenant_id non viene assegnato da solo, quindi lo passiamo.
        $site = Site::withoutTenancy()->firstOrCreate(
            ['domain' => 'slimcms.it'],
            [
                'tenant_id' => $tenant->id,
                'name' => 'SlimCMS',
                'theme' => [
                    'carta' => '#f4f4f1',
                    'inchiostro' => '#16181c',
                    'segnale' => '#0f6b4a',
                ],
                'seo_defaults' => [
                    'og_image' => '/logo.svg',
                    'publisher' => 'Content is King Srl',
                ],
            ]
        );

        // Da qui in poi le Page sanno a quale sito appartengono.
        $site->useAsCurrent();

        Page::updateOrCreate(
            ['slug' => 'home'],
            [
                'title' => 'Un CMS per chi gestisce venti siti, non uno',
                'status' => 'published',
                'publish_at' => now(),
                'blocks' => [
                    [
                        'tipo' => 'hero',
                        'occhiello' => 'Piattaforma CMS multitenant',
                        'titolo' => 'Un CMS per chi gestisce venti siti, non uno.',
                        'testo' => 'SlimCMS sostituisce WordPress quando i siti da mantenere sono tanti e piccoli. Un pannello solo per tutti. Pagine pubbliche generate staticamente, servite dalla CDN, che non toccano mai il backend.',
                    ],
                ],
                'seo' => [
                    'meta_title' => 'SlimCMS — un CMS per chi gestisce venti siti, non uno',
                    'meta_description' => 'Piattaforma CMS multitenant: un pannello per tutti i siti, pagine statiche servite da CDN, SEO/GEO/AEO integrati.',
                    'canonical_url' => 'https://slimcms.it/',
                    'noindex' => false,
                    'structured_summary' => "SlimCMS e' una piattaforma CMS multitenant che sostituisce WordPress nella gestione di molti mini siti aziendali. Un solo pannello amministra tutti i siti; le pagine pubbliche sono generate staticamente e servite da CDN, senza chiamare il backend.",
                    'key_facts' => [
                        'Un solo pannello di amministrazione per tutti i siti gestiti.',
                        'Le pagine pubbliche sono statiche: nessuna query al database quando un visitatore apre il sito.',
                        'Ogni pagina genera automaticamente JSON-LD, sitemap.xml e robots.txt.',
                        "L'isolamento fra i dati di clienti diversi e' applicato dal database in su, non dal frontend.",
                    ],
                    'schema_type' => 'SoftwareApplication',
                ],
            ]
        );

        Site::forgetCurrent();

        $this->command?->info("Sito pilota pronto: {$site->domain} (tenant {$tenant->name}).");
    }
}
