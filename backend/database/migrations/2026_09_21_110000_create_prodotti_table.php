<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Il catalogo del negozio.
 *
 * Gli importi sono interi in centesimi, IVA inclusa: con i decimali
 * 3 x 33,33 fa 99,99 e la differenza si scopre in contabilita'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prodotti', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('nome');
            $table->string('slug');
            $table->text('descrizione')->nullable();
            $table->unsignedInteger('prezzo');
            $table->unsignedInteger('prezzo_barrato')->nullable();
            // Unsigned: il database stesso rifiuta un magazzino negativo.
            $table->unsignedInteger('scorte')->default(0);
            $table->json('blocks')->nullable();
            $table->json('seo')->nullable();
            $table->string('status')->default('draft');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['site_id', 'slug']);
            $table->index(['site_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prodotti');
    }
};
