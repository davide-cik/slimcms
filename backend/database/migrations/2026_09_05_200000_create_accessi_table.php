<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Il registro degli accessi ai due pannelli.
 *
 * Serve a rispondere a una domanda sola: qualcuno sta provando a entrare che
 * non dovrebbe? Per rispondere servono anche i tentativi **falliti**, che per
 * definizione non hanno un utente: da qui `user_id` nullabile e l'email
 * tentata conservata a parte.
 *
 * NON e' scoped per sito. Un tentativo fallito non appartiene a nessun sito —
 * l'email potrebbe non esistere affatto — e la domanda "chi prova a entrare
 * nel CMS" e' di piattaforma, quindi si legge dal control plane dove un sito
 * corrente non c'e'. Stessa ragione di `impersonazioni`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accessi', function (Blueprint $table) {
            $table->id();

            // 'web' = pannello dei contenuti (User), 'manage' = control plane
            // (AdminUser). Sono due tabelle utente diverse di proposito, e la
            // colonna dice quale.
            $table->string('guardia', 12);

            // Nullabile: un tentativo fallito non ha un utente. Non e' una
            // chiave esterna proprio per questo, e perche' punta a due
            // tabelle diverse a seconda della guardia.
            $table->unsignedBigInteger('utente_id')->nullable();

            // Sempre presente, anche quando l'utente non esiste: e' l'unica
            // cosa che si sa di chi ha provato.
            $table->string('email', 180)->nullable();
            $table->string('nome', 120)->nullable();

            // riuscito | fallito | uscita | bloccato
            $table->string('esito', 12);

            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 300)->nullable();

            // Un accesso aperto impersonando dal control plane non e' un
            // accesso del redattore: senza questa distinzione una modifica
            // dell'assistenza sembrerebbe fatta dal cliente.
            $table->boolean('impersonato')->default(false);

            $table->timestamps();

            $table->index(['esito', 'created_at']);
            $table->index(['ip', 'created_at']);
            $table->index(['email', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accessi');
    }
};
