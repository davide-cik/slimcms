<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accessi di un amministratore di piattaforma al pannello di un sito.
 *
 * NON e' un accesso diretto: il control plane e il data plane restano due
 * identita' separate. Un super-admin puo' entrare nel pannello contenuti
 * SOLO impersonando un redattore esistente, con un token monouso che scade in
 * un minuto, e resta traccia di chi e' entrato, quando e su quale sito.
 *
 * La differenza rispetto a "dare l'accesso al super-admin" e' l'attribuzione:
 * senza, una modifica fatta dall'assistenza sarebbe indistinguibile da una
 * fatta dal cliente. Con la telefonata "io non ho toccato niente" che segue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('impersonazioni', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->foreignId('admin_user_id')->constrained('admin_users')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('ip', 45)->nullable();
            $table->timestamp('usato_il')->nullable();
            $table->timestamp('terminata_il')->nullable();
            $table->timestamps();

            $table->index(['admin_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impersonazioni');
    }
};
