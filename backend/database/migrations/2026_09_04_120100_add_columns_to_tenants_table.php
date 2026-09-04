<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * stancl/tenancy crea "tenants" con solo id (string) + data (json).
 * Qui aggiungiamo le colonne del control plane previste dalle specifiche.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('name')->after('id');
            $table->string('slug')->unique()->after('name');
            $table->string('status')->default('trial')->after('slug');
            $table->foreignId('plan_id')->nullable()->after('status')->constrained('plans')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plan_id');
            $table->dropColumn(['name', 'slug', 'status']);
        });
    }
};
