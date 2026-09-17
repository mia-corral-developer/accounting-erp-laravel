<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\Concerns\BelongsToCurrentTeam;
use App\Support\Tenancy\Concerns\CentralModel;
use App\Support\Tenancy\Concerns\SharedOrTenantModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Symfony\Component\Finder\Finder;

/**
 * Discovers Eloquent models and reports their tenancy posture (ADR-001 §3.2).
 *
 * Ground truth for "tenant-owned" is the **schema**: a model is tenant-owned
 * when its table carries a `team_id` column. Whether it is *protected* is a
 * property of the model class (does it register the fail-closed
 * {@see BelongsToCurrentTeam} scope, or declare {@see CentralModel} /
 * {@see SharedOrTenantModel}).
 *
 * This is the engine behind the isolation canary
 * (tests/Feature/Tenancy/TenantIsolationCanaryTest) and the reason a new
 * unprotected tenant table cannot reach production unnoticed.
 */
final class TenantModelRegistry
{
    public function __construct(private readonly string $basePath) {}

    /**
     * Every concrete Eloquent model class declared under `app/Models` and any
     * `modules/ *&#47;src/Models` directory.
     *
     * @return list<class-string<Model>>
     */
    public function modelClasses(): array
    {
        $dirs = [$this->basePath.'/app/Models'];
        foreach (glob($this->basePath.'/modules/*/src/Models') ?: [] as $dir) {
            $dirs[] = $dir;
        }

        $classes = [];

        foreach ($dirs as $dir) {
            if (! is_dir($dir)) {
                continue;
            }

            foreach (Finder::create()->files()->in($dir)->name('*.php') as $file) {
                $class = $this->classFromFile($file->getRealPath());

                if ($class === null) {
                    continue;
                }

                $classes[] = $class;
            }
        }

        sort($classes);

        return $classes;
    }

    /**
     * Table names whose schema carries a `team_id` column.
     *
     * @return array<string, true>
     */
    public function tenantTables(): array
    {
        $tables = [];

        foreach (Schema::getTables() as $table) {
            $name = is_array($table) ? ($table['name'] ?? null) : $table;

            if (! is_string($name) || $name === '') {
                continue;
            }

            foreach (Schema::getColumns($name) as $column) {
                if (($column['name'] ?? null) === 'team_id') {
                    $tables[$name] = true;
                    break;
                }
            }
        }

        return $tables;
    }

    /**
     * Tenant-owned models with their posture.
     *
     * @return array<class-string<Model>, array{table: string, category: ?string, protected: bool}>
     */
    public function tenantOwned(): array
    {
        $tenantTables = $this->tenantTables();
        $result = [];

        foreach ($this->modelClasses() as $class) {
            try {
                $table = (new $class())->getTable();
            } catch (\Throwable) {
                continue;
            }

            if (! isset($tenantTables[$table])) {
                continue;
            }

            $result[$class] = [
                'table' => $table,
                'category' => $this->category($class),
                'protected' => $this->isProtected($class),
            ];
        }

        return $result;
    }

    /**
     * The declared tenancy category of a model, or null when it declares none.
     *
     * - `tenant_owned`     — uses the fail-closed {@see BelongsToCurrentTeam} scope.
     * - `central`           — declares {@see CentralModel}.
     * - `shared_or_tenant`  — declares {@see SharedOrTenantModel}.
     */
    public function category(string $class): ?string
    {
        $uses = class_uses_recursive($class);

        return match (true) {
            isset($uses[BelongsToCurrentTeam::class]) => 'tenant_owned',
            isset($uses[CentralModel::class]) => 'central',
            isset($uses[SharedOrTenantModel::class]) => 'shared_or_tenant',
            default => null,
        };
    }

    /** A tenant-owned model is protected only when the fail-closed scope is on. */
    public function isProtected(string $class): bool
    {
        return isset(class_uses_recursive($class)[BelongsToCurrentTeam::class]);
    }

    /** @return class-string<Model>|null */
    private function classFromFile(string $path): ?string
    {
        $source = @file_get_contents($path);

        if ($source === false) {
            return null;
        }

        if (! preg_match('/^namespace\s+([^;]+);/m', $source, $ns)) {
            return null;
        }

        if (! preg_match('/\b(?:final\s+|abstract\s+)?class\s+(\w+)/', $source, $cls)) {
            return null;
        }

        $fqcn = trim($ns[1]).'\\'.$cls[1];

        if (! class_exists($fqcn) || ! is_subclass_of($fqcn, Model::class)) {
            return null;
        }

        if ((new ReflectionClass($fqcn))->isAbstract()) {
            return null;
        }

        /** @var class-string<Model> $fqcn */
        return $fqcn;
    }
}
