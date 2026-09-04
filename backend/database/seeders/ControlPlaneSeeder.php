<?php

namespace Database\Seeders;

use App\Models\AdminUser;
use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Utenti del control plane.
 *
 * Nota: sono identita' SEPARATE da quelle dei redattori, anche quando
 * l'indirizzo email coincide. La stessa persona che amministra la
 * piattaforma e redige contenuti ha due account distinti, di proposito.
 */
class ControlPlaneSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('Questo seeder non gira in produzione.');

            return;
        }

        AdminUser::firstOrCreate(
            ['email' => 'davide@giansoldati.it'],
            ['name' => 'Davide', 'password' => Hash::make('password'), 'role' => 'super-admin']
        );

        $supporto = AdminUser::firstOrCreate(
            ['email' => 'supporto@slimcms.it'],
            ['name' => 'Operatore Supporto', 'password' => Hash::make('password'), 'role' => 'support']
        );

        // Supporto limitato a un solo cliente: serve a verificare che
        // "supporto scoped" non sia solo un'etichetta sul ruolo.
        if ($id = Tenant::where('slug', 'studio-rossi')->value('id')) {
            $supporto->tenants()->syncWithoutDetaching([$id]);
        }

        $this->command?->info('Control plane: ' . AdminUser::count() . ' amministratori.');
    }
}
