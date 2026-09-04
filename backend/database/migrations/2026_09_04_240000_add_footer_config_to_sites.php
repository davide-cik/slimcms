<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configurazione del footer, per sito.
 *
 * Una colonna JSON sola come per og_config: sono impostazioni coese che si
 * toccano insieme, e aggiungere una voce al footer non deve richiedere una
 * migrazione.
 *
 * Chiavi previste:
 *   tipo      'semplice' | 'colonne'
 *   colonne   1 | 2 | 3            (solo per il tipo 'colonne')
 *   blocchi   [{ titolo, voci: [{ etichetta, url }] }]
 *   legale    riga in fondo
 *   firma     mostra la riga con le icone
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->json('footer_config')->nullable()->after('og_config');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('footer_config');
        });
    }
};
