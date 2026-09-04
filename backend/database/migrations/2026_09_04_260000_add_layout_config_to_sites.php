<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La testata (marchio e navigazione) era cablata nel layout Astro: cambiare
 * una voce di menu voleva dire toccare il codice e rifare il deploy. Da qui
 * in poi sta nel CMS come tutto il resto del sito.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->json('layout_config')->nullable()->after('footer_config');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('layout_config');
        });
    }
};
