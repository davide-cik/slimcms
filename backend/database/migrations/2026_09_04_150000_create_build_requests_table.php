<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro delle build: serve per il debounce e per il monitoraggio.
 *
 * Le specifiche (7.1) prevedono Horizon come dashboard delle code, ma su
 * questa macchina pcntl e' disabilitato in php.ini e Horizon non puo' girare
 * (nemmeno queue:work in modalita' daemon). Questa tabella fornisce lo stesso
 * il dato che serve: cosa e' in coda, da quanto, e cosa e' fallito.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('build_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('reason');
            $table->string('scope')->default('incremental'); // incremental | full
            $table->json('paths')->nullable();
            $table->string('status')->default('pending'); // pending | running | done | failed
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            // Fine della finestra di debounce: prima di quest'ora il job non parte.
            $table->timestamp('run_after');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            // Trovare in fretta la prossima build da eseguire e, per il
            // debounce, quella gia' in attesa per lo stesso sito.
            $table->index(['site_id', 'status']);
            $table->index(['status', 'run_after']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('build_requests');
    }
};
