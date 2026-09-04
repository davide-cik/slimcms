<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Colonne per l'autenticazione a due fattori con app authenticator (TOTP).
 *
 * Il segreto e i codici di recupero sono cifrati a riposo tramite i cast
 * 'encrypted' sui modelli: chi legge il database non deve poter rigenerare
 * i codici TOTP di nessuno.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['users', 'admin_users'] as $tabella) {
            Schema::table($tabella, function (Blueprint $table) {
                $table->text('app_authentication_secret')->nullable();
                $table->text('app_authentication_recovery_codes')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['users', 'admin_users'] as $tabella) {
            Schema::table($tabella, function (Blueprint $table) {
                $table->dropColumn(['app_authentication_secret', 'app_authentication_recovery_codes']);
            });
        }
    }
};
