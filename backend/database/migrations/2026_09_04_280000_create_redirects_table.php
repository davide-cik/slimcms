<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * I reindirizzamenti di un sito.
 *
 * Il sito pubblico e' statico e non passa da Laravel: queste righe non
 * vengono lette a ogni richiesta, vengono COMPILATE in un .htaccess durante
 * la build e depositate nel sito. E' lo stesso principio della mappa di
 * routing (specifiche §7.2): la risoluzione di un indirizzo non sta nel
 * percorso di lettura.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redirects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('da', 500);
            $table->string('a', 1000);
            $table->unsignedSmallInteger('codice')->default(301);
            $table->boolean('attivo')->default(true);
            $table->string('nota', 300)->nullable();
            $table->timestamps();

            // Un percorso di partenza puo' avere una sola destinazione: due
            // regole sullo stesso indirizzo verrebbero risolte dall'ordine di
            // scrittura nel file, cioe' a caso.
            $table->unique(['site_id', 'da']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redirects');
    }
};
