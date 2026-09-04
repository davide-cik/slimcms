<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Page;
use App\Models\Post;
use App\Models\User;
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
                'blocks' => ContenutoHomeSlimcms::blocchi(),
                'seo' => ContenutoHomeSlimcms::seo(),
            ]
        );

        Page::updateOrCreate(
            ['slug' => 'chi-siamo'],
            [
                'title' => 'Chi siamo',
                'status' => 'published',
                'publish_at' => now(),
                'blocks' => [[
                    'tipo' => 'hero',
                    'occhiello' => 'La squadra',
                    'titolo' => 'Chi siamo',
                    'testo' => 'SlimCMS e sviluppato da Content is King Srl, a Varedo. Gestiamo mini siti per i nostri clienti da anni: la piattaforma nasce dai problemi che avevamo noi.',
                ]],
                'seo' => [
                    'meta_title' => 'Chi siamo — SlimCMS',
                    'meta_description' => 'SlimCMS e sviluppato da Content is King Srl, agenzia di Varedo che gestisce mini siti per i propri clienti.',
                    'structured_summary' => 'SlimCMS e sviluppato da Content is King Srl, con sede a Varedo in provincia di Monza e Brianza. La piattaforma nasce dall esigenza di gestire molti mini siti di clienti senza mantenere altrettante installazioni separate.',
                    'schema_type' => 'Organization',
                ],
            ]
        );

        // Un articolo di blog reale, cosi' il pilota esercita anche il
        // percorso Post e non solo quello Page.
        $categoria = Category::firstOrCreate(
            ['slug' => 'dietro-le-quinte'],
            ['name' => 'Dietro le quinte']
        );

        $autore = User::withoutSitePivotScope()->where('email', 'davide@giansoldati.it')->first();

        $articolo = Post::updateOrCreate(
            ['slug' => 'perche-abbiamo-lasciato-wordpress'],
            [
                'title' => 'Perche abbiamo lasciato WordPress',
                'author_id' => $autore?->id,
                'excerpt' => 'Venti siti da aggiornare, plugin che si rompono a turno, e nessun modo di sapere quale sara il prossimo. La storia di come siamo arrivati a scrivere SlimCMS.',
                'tags' => ['wordpress', 'migrazione', 'performance'],
                'status' => 'published',
                'publish_at' => now()->subDay(),
                'blocks' => [[
                    'tipo' => 'testo_ricco',
                    'corpo' => '<p>Il problema non era WordPress in se, ma la moltiplicazione: venti installazioni separate, ognuna con i suoi aggiornamenti, i suoi plugin e i suoi orari in cui qualcosa si rompeva.</p>',
                ]],
                'seo' => [
                    'meta_title' => 'Perche abbiamo lasciato WordPress — SlimCMS',
                    'meta_description' => 'Venti siti da mantenere e nessun modo di sapere quale plugin si sarebbe rotto per primo.',
                    'structured_summary' => "SlimCMS nasce dalla difficolta di mantenere molti mini siti WordPress separati: ogni installazione richiedeva aggiornamenti e plugin propri.",
                    'key_facts' => ['Ogni sito WordPress separato richiede aggiornamenti indipendenti.'],
                ],
            ]
        );

        $articolo->categories()->syncWithoutDetaching([$categoria->id]);

        Site::forgetCurrent();

        $this->command?->info("Sito pilota pronto: {$site->domain} (tenant {$tenant->name}).");
    }
}
