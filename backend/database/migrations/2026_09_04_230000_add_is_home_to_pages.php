<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Flag esplicito per la pagina iniziale di un sito.
 *
 * Prima la home era riconosciuta dallo slug magico "home": una convenzione
 * implicita, che si rompe appena qualcuno rinomina lo slug o vuole che la
 * pagina iniziale sia un'altra. Con un flag la cosa e' dichiarata, e il
 * frontend non deve piu' indovinare.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->boolean('is_home')->default(false)->after('slug');
            $table->index(['site_id', 'is_home']);
        });

        // Le pagine con slug "home" diventano la home del proprio sito.
        DB::table('pages')->where('slug', 'home')->update(['is_home' => true]);

        // Un sito senza nessuna home resterebbe senza pagina iniziale: si
        // promuove la piu' vecchia, che e' quasi sempre quella giusta.
        $senzaHome = DB::table('pages')
            ->select('site_id')
            ->groupBy('site_id')
            ->havingRaw('SUM(is_home) = 0')
            ->pluck('site_id');

        foreach ($senzaHome as $siteId) {
            $prima = DB::table('pages')->where('site_id', $siteId)
                ->orderBy('id')->value('id');

            if ($prima !== null) {
                DB::table('pages')->where('id', $prima)->update(['is_home' => true]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropIndex(['site_id', 'is_home']);
            $table->dropColumn('is_home');
        });
    }
};
