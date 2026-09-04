<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configurazione delle immagini Open Graph, per sito.
 *
 * Una colonna JSON sola invece di cinque colonne: sono impostazioni coese che
 * si toccano sempre insieme, e ogni nuovo campo dell'immagine non richiede una
 * migrazione.
 *
 * Chiavi previste: larghezza, altezza, payoff, cta, legale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->json('og_config')->nullable()->after('favicon_initials');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('og_config');
        });
    }
};
