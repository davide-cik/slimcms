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
                'blocks' => ContenutoHomeSlimcms::blocchi(),
                'seo' => ContenutoHomeSlimcms::seo(),
            ]
        );

        Site::forgetCurrent();

        $this->command?->info("Sito pilota pronto: {$site->domain} (tenant {$tenant->name}).");
    }
}
