<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * I 404 osservati sul sito, aggregati per percorso.
 *
 * Il sito e' statico e un 404 non tocca Laravel. La pagina d'errore e' pero'
 * un piccolo file PHP sul dominio del sito: Apache gli passa l'indirizzo
 * richiesto, lui annota una riga in una cartella privata e stampa la pagina
 * gia' costruita. Un comando importa quelle righe qui.
 *
 * Cosi' si vedono anche i 404 dei crawler e delle immagini, che una
 * segnalazione JavaScript dalla pagina non vedrebbe mai; e il percorso
 * d'errore non fa nessuna richiesta di rete, quindi resta veloce e funziona
 * anche a backend fermo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pagine_mancanti', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('percorso', 500);
            $table->unsignedInteger('colpi')->default(0);
            // Il referrer e' il discriminante fra un collegamento rotto e uno
            // scanner che tira a indovinare: senza, l'elenco e' quasi tutto
            // rumore e diventa un allarme che si impara a ignorare.
            $table->unsignedInteger('colpi_con_referrer')->default(0);
            $table->string('ultimo_referrer', 500)->nullable();
            $table->timestamp('primo_il')->nullable();
            $table->timestamp('ultimo_il')->nullable();
            $table->boolean('ignorata')->default(false);
            $table->timestamps();

            $table->unique(['site_id', 'percorso']);
            $table->index(['site_id', 'colpi_con_referrer']);
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('pagine_mancanti');
    }
};
