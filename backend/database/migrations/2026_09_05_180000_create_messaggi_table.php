<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * I messaggi del form di contatto.
 *
 * Finiscono in tabella PRIMA di qualsiasi tentativo di invio. Un form che
 * risponde "messaggio ricevuto" e poi scrive una riga di log e' un modo per
 * perdere richieste commerciali senza accorgersene: se la mail non parte —
 * mailer non configurato, casella piena, destinatario sbagliato — il
 * messaggio deve restare comunque leggibile dal pannello.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messaggi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('nome', 120);
            $table->string('email', 180);
            $table->text('messaggio');
            // Da quale pagina e' partito: su un sito di dieci pagine cambia
            // il modo di rispondere.
            $table->string('pagina', 300)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 300)->nullable();
            $table->timestamp('letto_il')->nullable();
            $table->timestamps();

            $table->index('site_id');
            $table->index(['site_id', 'letto_il']);
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->string('contact_email', 180)->nullable()->after('seo_defaults');
        });
    }

    public function down(): void
    {
        Schema::table('sites', fn (Blueprint $table) => $table->dropColumn('contact_email'));
        Schema::dropIfExists('messaggi');
    }
};
