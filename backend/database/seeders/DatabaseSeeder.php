<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Ricostruisce l'ambiente di sviluppo da zero.
 *
 * Serve dopo ogni `php artisan test`, che azzera il database
 * (RefreshDatabase fa migrate:fresh). Il contenuto reale di slimcms.it
 * sta in ContenutoHomeSlimcms, quindi e' interamente riproducibile.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SlimcmsPilotSeeder::class,
            DemoMultiTenantSeeder::class,
        ]);
    }
}
