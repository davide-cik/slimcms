<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Utenti del control plane, in una tabella SEPARATA da users.
 *
 * Non e' un ruolo in piu' su users: un redattore e un amministratore di
 * piattaforma sono identita' diverse con superfici di accesso diverse. Con
 * una tabella sola, un errore in una policy o una query dimenticata
 * trasformerebbe un redattore in super-admin. Separarle rende quell'errore
 * impossibile per costruzione, non solo improbabile.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('role')->default('support'); // super-admin | support
            $table->rememberToken();
            $table->timestamps();
        });

        // Supporto scoped: un operatore di assistenza puo' essere limitato a
        // specifici clienti invece di vederli tutti.
        Schema::create('admin_user_tenant', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_user_id')->constrained('admin_users')->cascadeOnDelete();
            $table->string('tenant_id');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['admin_user_id', 'tenant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_user_tenant');
        Schema::dropIfExists('admin_users');
    }
};
