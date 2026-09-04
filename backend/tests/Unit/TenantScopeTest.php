<?php

namespace Tests\Unit;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionClass;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;
use Tests\TestCase;

/**
 * TenantScopeTest
 *
 * Scopo: prevenire il rischio "modello con colonna di scoping ma senza il
 * trait applicato", che e' il tipo di errore silenzioso piu' pericoloso in
 * un'app multitenant (un nuovo modello che espone dati fra tenant diversi
 * senza che nessun test funzionale se ne accorga).
 *
 * SlimCMS ha DUE livelli di isolamento, con due trait distinti:
 *
 *   tenant_id -> Stancl\Tenancy\Database\Concerns\BelongsToTenant  (Site)
 *   site_id   -> App\Models\Concerns\BelongsToSite                 (Page, Post, Media)
 *
 * Il test scansiona tutti i modelli Eloquent dell'app e verifica la
 * corrispondenza nei due sensi: colonna presente => trait obbligatorio,
 * trait presente => colonna obbligatoria.
 *
 * Se un test qui fallisce, la correzione e' AGGIUNGERE IL TRAIT MANCANTE.
 * Non aggiungere il modello a EXCLUDED_MODELS per far tornare il verde:
 * significherebbe disattivare l'isolamento proprio dove serve.
 */
class TenantScopeTest extends TestCase
{
    /**
     * Mappa colonna di scoping => trait che deve gestirla.
     */
    private const TENANT_COLUMN_TRAITS = [
        'tenant_id' => BelongsToTenant::class,
        'site_id' => BelongsToSite::class,
    ];

    /**
     * Modelli esplicitamente esclusi dal controllo, perche' per design NON
     * sono scoped: vivono a livello di piattaforma e non di singolo sito.
     *
     * Aggiungere un modello qui deve essere una scelta esplicita e
     * consapevole, mai una dimenticanza, e va motivata nel commento.
     *
     * Nota: Site NON e' in questa lista. Site ha tenant_id e deve essere
     * scoped per tenant, cosi' nessuna query puo' leggere il sito di un
     * altro cliente nemmeno per errore.
     */
    private const EXCLUDED_MODELS = [
        \App\Models\Tenant::class,  // e' il tenant stesso, non un dato al suo interno
        \App\Models\Plan::class,    // catalogo commerciale di piattaforma
        \App\Models\BuildRequest::class, // lavoro di piattaforma, non contenuto:
                                    // i comandi in console devono vedere le build
                                    // di tutti i siti per eseguirle in ordine
        \App\Models\User::class,    // TODO: da rivedere quando esistera' il pivot site_user.
                                    // Oggi passa solo perche' la tabella users e' ancora
                                    // quella di default e non ha site_id: esclusione NON progettata.
    ];

    public function test_ogni_modello_con_colonna_di_scoping_usa_il_trait_corrispondente(): void
    {
        $failures = [];

        foreach ($this->getAllModelClasses() as $modelClass) {
            if (in_array($modelClass, self::EXCLUDED_MODELS, true)) {
                continue;
            }

            $table = (new $modelClass())->getTable();
            $traits = class_uses_recursive($modelClass);

            foreach (self::TENANT_COLUMN_TRAITS as $column => $trait) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                if (! in_array($trait, $traits, true)) {
                    $failures[] = sprintf(
                        '%s ha la colonna "%s" nella tabella "%s" ma NON usa il trait %s.',
                        $modelClass,
                        $column,
                        $table,
                        $trait
                    );
                }
            }
        }

        $this->assertEmpty(
            $failures,
            "Modelli scoped senza il trait corrispondente:\n" . implode("\n", $failures)
            . "\n\nLa correzione e' aggiungere il trait. Se il modello e' intenzionalmente "
            . 'escluso dallo scoping, aggiungerlo a TenantScopeTest::EXCLUDED_MODELS con un '
            . 'commento che spiega il perche.'
        );
    }

    /**
     * Verifica il caso inverso: un modello che usa un trait ma la cui
     * tabella NON ha la colonna attesa fallirebbe silenziosamente a
     * runtime. Meglio scoprirlo qui che in produzione.
     */
    public function test_ogni_modello_che_usa_un_trait_ha_la_colonna_corrispondente(): void
    {
        $failures = [];

        foreach ($this->getAllModelClasses() as $modelClass) {
            $table = (new $modelClass())->getTable();
            $traits = class_uses_recursive($modelClass);

            foreach (self::TENANT_COLUMN_TRAITS as $column => $trait) {
                if (! in_array($trait, $traits, true)) {
                    continue;
                }

                if (! Schema::hasColumn($table, $column)) {
                    $failures[] = sprintf(
                        '%s usa il trait %s ma la tabella "%s" non ha la colonna "%s".',
                        $modelClass,
                        $trait,
                        $table,
                        $column
                    );
                }
            }
        }

        $this->assertEmpty(
            $failures,
            "Modelli con trait ma senza la colonna corrispondente:\n" . implode("\n", $failures)
        );
    }

    /**
     * Scansiona app/Models e restituisce i nomi completi delle classi
     * modello, escludendo trait e classi astratte.
     */
    private function getAllModelClasses(): array
    {
        $modelsPath = app_path('Models');
        $classes = [];

        foreach (File::allFiles($modelsPath) as $file) {
            $relativePath = Str::after($file->getPathname(), $modelsPath . DIRECTORY_SEPARATOR);
            $class = 'App\\Models\\' . Str::replace(['/', '.php'], ['\\', ''], $relativePath);

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
                continue;
            }

            $classes[] = $class;
        }

        return $classes;
    }
}
