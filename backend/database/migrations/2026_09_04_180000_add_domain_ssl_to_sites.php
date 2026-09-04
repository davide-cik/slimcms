<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stato del dominio e del certificato di ogni sito.
 *
 * Le specifiche (sezione 11) chiedono un alert se un certificato non si
 * rinnova, "per non ritrovarsi un sito cliente offline per certificato
 * scaduto". Serve quindi tenere traccia dello stato, non solo emetterlo:
 * un certificato che scade in silenzio e' il modo tipico in cui un sito
 * cliente sparisce senza che nessuno se ne accorga fino alla telefonata.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('ssl_status')->default('sconosciuto')->after('domain');
            $table->timestamp('ssl_expires_at')->nullable()->after('ssl_status');
            $table->timestamp('ssl_checked_at')->nullable()->after('ssl_expires_at');
            $table->text('ssl_last_error')->nullable()->after('ssl_checked_at');
            $table->string('dns_status')->default('sconosciuto')->after('ssl_last_error');

            $table->index('ssl_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn([
                'ssl_status', 'ssl_expires_at', 'ssl_checked_at',
                'ssl_last_error', 'dns_status',
            ]);
        });
    }
};
