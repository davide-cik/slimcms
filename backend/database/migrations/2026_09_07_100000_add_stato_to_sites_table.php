<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stato del ciclo di vita di un sito: attivo, parcheggiato, sospeso.
 *
 * `tenants` aveva gia' uno stato, ma e' del CLIENTE: un cliente puo' avere
 * dieci siti e volerne fermare uno. Sono due domande diverse e servono due
 * colonne.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // Default 'attivo': i siti che esistono gia' lo sono, e un sito
            // nuovo nasce online salvo decisione contraria.
            $table->string('stato', 20)->default('attivo')->after('domain');

            // Una riga in piu' sulla pagina di cortesia: "torniamo il 12
            // marzo", "sito in arrivo a settembre". Facoltativa.
            $table->string('nota_cortesia', 200)->nullable()->after('stato');

            $table->index('stato');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropIndex(['stato']);
            $table->dropColumn(['stato', 'nota_cortesia']);
        });
    }
};
