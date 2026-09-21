<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Il negozio e' un modulo che la piattaforma accende sito per sito.
 *
 * Default spento: i siti che esistono non vendono niente, e accendere il
 * negozio a tutti con una migrazione farebbe comparire voci di menu che
 * nessuno ha chiesto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->boolean('shop_attivo')->default(false)->after('nota_cortesia');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('shop_attivo');
        });
    }
};
