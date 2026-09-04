<?php

namespace Database\Seeders;

use App\Models\Page;
use App\Models\Plan;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Dati demo con DUE clienti distinti, per avere su cui provare davvero
 * l'isolamento invece di fidarsi del fatto che il codice sembri giusto.
 *
 * Struttura:
 *   Content is King Srl  -> slimcms.it, blog.slimcms.it   -> davide (admin)
 *   Studio Rossi Srl     -> studiorossi.test, news.studiorossi.test -> anna (admin), luca (editor su un solo sito)
 *
 * Solo per ambiente locale: rifiuta di girare in produzione.
 */
class DemoMultiTenantSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('Questo seeder non gira in produzione.');

            return;
        }

        $plan = Plan::firstOrCreate(['name' => 'Interno'], [
            'price_monthly' => 0,
            'max_sites' => 100,
            'max_storage_gb' => 50,
            'features_included' => ['seo', 'geo', 'aeo'],
        ]);

        $studio = Tenant::firstOrCreate(['slug' => 'studio-rossi'], [
            'id' => 'studio-rossi',
            'name' => 'Studio Rossi Srl',
            'status' => 'active',
            'plan_id' => $plan->id,
        ]);

        $cik = Tenant::firstOrCreate(['slug' => 'content-is-king'], [
            'id' => 'content-is-king',
            'name' => 'Content is King Srl',
            'status' => 'active',
            'plan_id' => $plan->id,
        ]);

        $blog = $this->sito($cik, 'blog.slimcms.it', 'SlimCMS — Blog');
        $sr1 = $this->sito($studio, 'studiorossi.test', 'Studio Rossi');
        $sr2 = $this->sito($studio, 'news.studiorossi.test', 'Studio Rossi — Notizie');

        $this->paginaDiBenvenuto($blog, 'Il blog di SlimCMS');
        $this->paginaDiBenvenuto($sr1, 'Studio Rossi, commercialisti a Monza');
        $this->paginaDiBenvenuto($sr2, 'Le notizie dello Studio Rossi');

        $slimcms = Site::withoutTenancy()->where('domain', 'slimcms.it')->first();

        // davide: admin su entrambi i siti di Content is King
        $this->utente('davide@giansoldati.it', 'Davide', [
            $slimcms?->id => 'admin',
            $blog->id => 'admin',
        ]);

        // anna: admin su entrambi i siti dello Studio Rossi
        $this->utente('anna@studiorossi.test', 'Anna Rossi', [
            $sr1->id => 'admin',
            $sr2->id => 'admin',
        ]);

        // luca: editor su UN SOLO sito dello Studio Rossi.
        // Serve a verificare il caso piu' stretto: non deve vedere
        // nemmeno l'altro sito del suo stesso cliente.
        $this->utente('luca@studiorossi.test', 'Luca Bianchi', [
            $sr2->id => 'editor',
        ]);

        $this->command?->info('Demo: 2 clienti, 4 siti, 3 utenti.');
    }

    private function sito(Tenant $tenant, string $domain, string $name): Site
    {
        return Site::withoutTenancy()->firstOrCreate(
            ['domain' => $domain],
            ['tenant_id' => $tenant->id, 'name' => $name]
        );
    }

    private function paginaDiBenvenuto(Site $site, string $titolo): void
    {
        $site->useAsCurrent();

        Page::updateOrCreate(['slug' => 'home'], [
            'title' => $titolo,
            'status' => 'published',
            'publish_at' => now(),
            // Formato del Builder di Filament: tipo e dati separati.
            'blocks' => [[
                'type' => 'hero',
                'data' => [
                    'titolo' => $titolo,
                    'testo' => 'Pagina di esempio generata dal seeder demo.',
                ],
            ]],
            'seo' => [
                'meta_title' => $titolo,
                'meta_description' => 'Pagina di esempio di ' . $site->name . '.',
            ],
        ]);

        Site::forgetCurrent();
    }

    /**
     * @param  array<int|null, string>  $siti  id sito => ruolo
     */
    private function utente(string $email, string $nome, array $siti): User
    {
        $u = User::withoutSitePivotScope()->firstOrCreate(
            ['email' => $email],
            ['name' => $nome, 'password' => Hash::make('password')]
        );

        $pivot = [];
        foreach (array_filter($siti, fn ($k) => $k !== null, ARRAY_FILTER_USE_KEY) as $siteId => $ruolo) {
            $pivot[$siteId] = ['role' => $ruolo];
        }

        $u->sites()->syncWithoutDetaching($pivot);

        return $u;
    }
}
