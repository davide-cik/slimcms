<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Iniziali per la favicon generata.
 *
 * Se vuote, si ricavano dal nome del sito. Esistono come colonna perche' la
 * derivazione automatica sbaglia spesso: "Studio Rossi" da "SR", ma "Il
 * Girasole" darebbe "IG" quando il cliente vuole "G". Un campo modificabile
 * evita di dover indovinare regole che non esistono.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('favicon_initials', 3)->nullable()->after('favicon_path');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('favicon_initials');
        });
    }
};
