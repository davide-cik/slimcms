<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pivot fra utenti redattori e siti.
 *
 * Un redattore puo' lavorare su piu' mini siti dello stesso cliente, quindi
 * la relazione e' many-to-many e NON una colonna site_id su users: e' per
 * questo che User resta fuori da BelongsToSite.
 *
 * Il ruolo sta sul pivot, non sull'utente: la stessa persona puo' essere
 * admin su un sito e author su un altro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role')->default('editor');
            $table->timestamps();

            $table->unique(['site_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_user');
    }
};
