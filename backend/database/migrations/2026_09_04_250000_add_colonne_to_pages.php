<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Su quante colonne si dispone il contenuto di una pagina.
 *
 * Vale per i blocchi di corpo. L'apertura resta sempre a tutta larghezza:
 * un titolo di apertura dentro un terzo di pagina non e' un'apertura.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->unsignedTinyInteger('colonne')->default(1)->after('is_home');
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropColumn('colonne');
        });
    }
};
