<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le visite al sito, gia' aggregate per giorno.
 *
 * Non si conserva una riga per richiesta. Una tabella grezza cresce senza
 * limite, e per rispondere a "quanti accessi ieri" bisogna comunque
 * raggrupparla ogni volta: l'aggregazione si fa una volta sola,
 * nell'importazione.
 *
 * Non c'e' nessun dato personale. L'indirizzo IP non viene salvato: serve
 * solo, dentro l'importazione, a calcolare un'impronta con un sale che
 * cambia ogni giorno. Cosi' si contano i visitatori distinti di oggi senza
 * poterli riconoscere domani, e senza tenere niente che li identifichi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('viste', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->date('giorno');
            // umano | motore | ai | bot (App\Support\ClassificatoreAgente)
            $table->string('categoria', 12);
            $table->string('agente', 60);
            $table->string('percorso', 300);
            $table->unsignedInteger('conteggio')->default(0);
            // Quante di quelle richieste hanno poi eseguito JavaScript. Un
            // agente che dichiara un browser e non esegue mai niente e' uno
            // scanner travestito, ed e' l'unico modo di accorgersene.
            $table->unsignedInteger('con_js')->default(0);
            $table->timestamps();

            $table->unique(['site_id', 'giorno', 'categoria', 'agente', 'percorso'], 'viste_chiave');
            $table->index(['site_id', 'giorno']);
        });

        Schema::create('viste_impronte', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->date('giorno');
            // sha256(sale_del_giorno + ip + user agent), troncato. Il sale
            // cambia ogni giorno e non viene conservato: l'impronta di oggi
            // non e' confrontabile con quella di domani, e da sola non
            // risale a nessuno.
            $table->string('impronta', 32);
            $table->timestamps();

            $table->unique(['site_id', 'giorno', 'impronta'], 'impronte_chiave');
            $table->index(['site_id', 'giorno']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('viste_impronte');
        Schema::dropIfExists('viste');
    }
};
