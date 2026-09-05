<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * I moduli di un sito: non piu' un solo form di contatto cablato.
 *
 * Un sito ne ha quanti ne servono — contatti, richiesta preventivo,
 * iscrizione a un evento — ognuno con i propri campi, il proprio
 * destinatario e i propri messaggi.
 *
 * I campi stanno in JSON e non in una tabella a parte: sono un elenco
 * ordinato che si legge e si riscrive sempre per intero, mai una riga alla
 * volta, e una tabella figlia aggiungerebbe una join per ogni form senza
 * dare niente in cambio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('moduli', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('nome', 120);
            $table->string('slug', 120);
            $table->string('email_destinatario', 180)->nullable();
            $table->json('campi')->nullable();
            $table->string('messaggio_conferma', 200)->nullable();
            $table->boolean('attivo')->default(true);
            $table->timestamps();

            // Per sito, come per pagine e categorie: due clienti possono
            // avere entrambi un modulo "contatti".
            $table->unique(['site_id', 'slug']);
            $table->index('site_id');
        });

        Schema::table('messaggi', function (Blueprint $table) {
            // Nullabile: i messaggi arrivati prima che i moduli esistessero
            // restano leggibili. Una colonna obbligatoria avrebbe voluto dire
            // inventare un modulo per righe che non ne avevano uno.
            $table->foreignId('modulo_id')->nullable()->after('site_id')
                ->constrained('moduli')->nullOnDelete();

            // I campi oltre ai tre che ha ogni modulo di contatto. Nome,
            // email e messaggio restano colonne: sono quelli su cui si cerca
            // e si ordina, e finirebbero comunque estratti dal JSON a ogni
            // interrogazione.
            $table->json('dati')->nullable()->after('messaggio');

            $table->index(['site_id', 'modulo_id']);
        });

        // Impostazioni del captcha, per sito: il fornitore lo sceglie chi
        // gestisce il sito, non la piattaforma.
        Schema::table('sites', function (Blueprint $table) {
            $table->string('captcha_fornitore', 20)->nullable()->after('contact_email');
            $table->string('captcha_chiave_pubblica', 200)->nullable()->after('captcha_fornitore');
            // Cifrata a riposo (cast 'encrypted' sul modello): chi legge il
            // database non deve poter usare il captcha di un cliente.
            $table->text('captcha_segreto')->nullable()->after('captcha_chiave_pubblica');
        });
    }

    public function down(): void
    {
        Schema::table('sites', fn (Blueprint $t) => $t->dropColumn([
            'captcha_fornitore', 'captcha_chiave_pubblica', 'captcha_segreto',
        ]));

        Schema::table('messaggi', function (Blueprint $table) {
            $table->dropForeign(['modulo_id']);
            $table->dropColumn(['modulo_id', 'dati']);
        });

        Schema::dropIfExists('moduli');
    }
};
