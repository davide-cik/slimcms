<?php

namespace Tests\Unit;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ReflectionClass;
use Tests\TestCase;

/**
 * TenantScopeTest
 *
 * Scopo: prevenire il rischio "modello con colonna site_id/tenant_id
 * ma senza il trait BelongsToTenant applicato", che e' il tipo di
 * errore silenzioso piu' pericoloso in un'app multitenant (un nuovo
 * modello che espone dati tra tenant diversi senza che nessun test
 * funzionale se ne accorga).
 *
 * Questo test scansiona automaticamente TUTTI i modelli Eloquent
 * dell'app: se un modello ha una colonna "site_id" o "tenant_id" nella
 * tabella corrispondente, deve obbligatoriamente usare il trait
 * BelongsToTenant. Se qualcuno aggiunge un nuovo modello scoped e si
 * dimentica il trait, questo test fallisce in CI prima del merge.
 */
class TenantScopeTest extends TestCase
{
    /**
     * Nomi di colonna che identificano un modello come "scoped per tenant".
     * Estendere se in futuro si usano nomi diversi.
     */
    private const TENANT_COLUMN_CANDIDATES = ['site_id', 'tenant_id'];

    /**
     * Modelli esplicitamente esclusi dal controllo, perche' per design
     * NON sono scoped per tenant (es. modelli del control plane, che
     * vivono a livello di piattaforma e non di singolo sito).
     *
     * Aggiungere qui un modello deve essere una scelta esplicita e
     * consapevole, mai un dimenticanza.
     */
    private const EXCLUDED_MODELS = [
        \App\Models\Tenant::class,
        \App\Models\AdminUser::class,
        \App\Models\Plan::class,
        \App\Models\Site::class, // Site e' il "contenitore" del tenant, non un dato scoped al suo interno
    ];

    /** @test */
    public function ogni_modello_con_colonna_tenant_usa_il_trait_belongs_to_tenant(): void
    {
        $failures = [];

        foreach ($this->getAllModelClasses() as $modelClass) {
            if (in_array($modelClass, self::EXCLUDED_MODELS, true)) {
                continue;
            }

            $model = new $modelClass();
            $table = $model->getTable();

            if (!$this->tableHasTenantColumn($table)) {
                continue;
            }

            $usesTrait = in_array(
                BelongsToTenant::class,
                class_uses_recursive($modelClass),
                true
            );

            if (!$usesTrait) {
                $failures[] = sprintf(
                    '%s ha la colonna tenant nella tabella "%s" ma NON usa il trait BelongsToTenant.',
                    $modelClass,
                    $table
                );
            }
        }

        $this->assertEmpty(
            $failures,
            "Modelli scoped per tenant senza il trait BelongsToTenant:\n" . implode("\n", $failures)
            . "\n\nSe il modello e' intenzionalmente escluso dallo scoping, aggiungerlo a "
            . 'TenantScopeTest::EXCLUDED_MODELS con un commento che spiega il perche'."'
        );
    }

    /**
     * Verifica anche il caso inverso: un modello che usa il trait ma la
     * cui tabella NON ha la colonna attesa fallirebbe silenziosamente
     * a runtime. Meglio scoprirlo qui che in produzione.
     */
    /** @test */
    public function ogni_modello_che_usa_il_trait_ha_la_colonna_tenant_nella_tabella(): void
    {
        $failures = [];

        foreach ($this->getAllModelClasses() as $modelClass) {
            $usesTrait = in_array(
                BelongsToTenant::class,
                class_uses_recursive($modelClass),
                true
            );

            if (!$usesTrait) {
                continue;
            }

            $model = new $modelClass();
            $table = $model->getTable();

            if (!$this->tableHasTenantColumn($table)) {
                $failures[] = sprintf(
                    '%s usa il trait BelongsToTenant ma la tabella "%s" non ha una colonna site_id/tenant_id.',
                    $modelClass,
                    $table
                );
            }
        }

        $this->assertEmpty(
            $failures,
            "Modelli con trait ma senza colonna tenant nella tabella:\n" . implode("\n", $failures)
        );
    }

    /**
     * Scansiona app/Models e restituisce i nomi completi delle classi
     * modello, escludendo il namespace Concerns (i trait stessi).
     */
    private function getAllModelClasses(): array
    {
        $modelsPath = app_path('Models');
        $classes = [];

        foreach (File::allFiles($modelsPath) as $file) {
            $relativePath = Str::after($file->getPathname(), $modelsPath . DIRECTORY_SEPARATOR);
            $class = 'App\\Models\\' . Str::replace(
                ['/', '.php'],
                ['\\', ''],
                $relativePath
            );

            if (!class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || !$reflection->isSubclassOf(\Illuminate\Database\Eloquent\Model::class)) {
                continue;
            }

            $classes[] = $class;
        }

        return $classes;
    }

    private function tableHasTenantColumn(string $table): bool
    {
        foreach (self::TENANT_COLUMN_CANDIDATES as $column) {
            if (\Illuminate\Support\Facades\Schema::hasColumn($table, $column)) {
                return true;
            }
        }

        return false;
    }
}
