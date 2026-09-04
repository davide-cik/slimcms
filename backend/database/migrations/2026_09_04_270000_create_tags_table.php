<?php

use App\Support\Slug;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * I tag diventano un modello come le categorie.
 *
 * Erano una colonna JSON di stringhe libere sul post. Con quella forma un tag
 * non ha uno slug (quindi niente pagina d'archivio /tag/<slug>/), non si
 * rinomina in un colpo solo su tutti gli articoli, e "Performance" e
 * "performance" restano due cose diverse per sempre. Soprattutto non e'
 * scoped: il concetto di tag di un cliente non esisteva come riga, quindi
 * TenantScopeTest non poteva coprirlo.
 *
 * La colonna viene RIMOSSA nella stessa migrazione che crea la tabella: due
 * fonti per lo stesso dato sono esattamente il tipo di doppione che in questo
 * progetto ha gia' prodotto piu' di un guasto (Post::$tags risolverebbe
 * all'attributo e non alla relazione, e l'API continuerebbe a servire il
 * vecchio array mentre il pannello scrive la pivot).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->timestamps();

            // Univoco PER SITO, come per le categorie.
            $table->unique(['site_id', 'slug']);
        });

        Schema::create('post_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('tags')->cascadeOnDelete();

            $table->unique(['post_id', 'tag_id']);
        });

        $this->trasferisciTagEsistenti();

        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('tags');
        });
    }

    /**
     * Porta i tag gia' scritti negli articoli nella tabella nuova.
     *
     * Query grezze e non modelli: qui gira una migrazione, il contesto tenant
     * non c'e' (regola 2 del CLAUDE.md) e il site_id va preso dall'articolo,
     * non dal contesto corrente. Con i modelli il global scope nasconderebbe
     * gli articoli di tutti i siti tranne — nel migliore dei casi — uno.
     */
    private function trasferisciTagEsistenti(): void
    {
        $adesso = now();

        foreach (DB::table('posts')->select('id', 'site_id', 'tags')->get() as $post) {
            $etichette = json_decode((string) $post->tags, true);

            if (! is_array($etichette)) {
                continue;
            }

            foreach ($etichette as $etichetta) {
                $etichetta = trim((string) $etichetta);

                if ($etichetta === '') {
                    continue;
                }

                $slug = Slug::da($etichetta);

                if ($slug === '') {
                    continue;
                }

                $tagId = DB::table('tags')
                    ->where('site_id', $post->site_id)
                    ->where('slug', $slug)
                    ->value('id');

                $tagId ??= DB::table('tags')->insertGetId([
                    'site_id' => $post->site_id,
                    'name' => $etichetta,
                    'slug' => $slug,
                    'created_at' => $adesso,
                    'updated_at' => $adesso,
                ]);

                DB::table('post_tag')->insertOrIgnore([
                    'post_id' => $post->id,
                    'tag_id' => $tagId,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->json('tags')->nullable();
        });

        // Il ritorno indietro rimette le etichette dov'erano: una rollback
        // che perde dati non e' una rollback.
        foreach (DB::table('posts')->select('id')->get() as $post) {
            $etichette = DB::table('post_tag')
                ->join('tags', 'tags.id', '=', 'post_tag.tag_id')
                ->where('post_tag.post_id', $post->id)
                ->pluck('tags.name')
                ->all();

            DB::table('posts')->where('id', $post->id)->update([
                'tags' => $etichette === [] ? null : json_encode($etichette),
            ]);
        }

        Schema::dropIfExists('post_tag');
        Schema::dropIfExists('tags');
    }
};
