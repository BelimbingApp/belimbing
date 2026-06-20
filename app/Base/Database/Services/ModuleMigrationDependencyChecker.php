<?php

namespace App\Base\Database\Services;

use App\Base\Foundation\ModuleManifest\ModuleManifest;
use App\Base\Foundation\ModuleManifest\ModuleManifestException;
use App\Base\Foundation\ModuleManifest\ModuleManifestReader;
use App\Base\Foundation\Services\DomainState;

final class ModuleMigrationDependencyChecker
{
    /**
     * Migration directories that participate in BLB's module-aware migrator.
     *
     * @return list<string>
     */
    public function migrationPaths(): array
    {
        $paths = array_merge(
            glob(app_path('Base/*/Database/Migrations'), GLOB_ONLYDIR) ?: [],
            DomainState::filterPaths(glob(app_path('Modules/*/*/Database/Migrations'), GLOB_ONLYDIR) ?: []),
            glob(base_path('extensions/*/*/Database/Migrations'), GLOB_ONLYDIR) ?: [],
        );

        sort($paths);

        return $this->sortPathsByModuleGraph(array_values(array_unique($paths)));
    }

    /**
     * Fail before Laravel starts migrating when installed module manifests
     * declare impossible dependency requirements or migration filename order
     * does not respect the module graph.
     */
    public function assertReadyForMigration(): void
    {
        $reader = $this->reader();
        $manifests = $reader->all();
        $moduleRoots = $reader->moduleRoots();

        $dependencyIssues = $reader->dependencyIssues($manifests);
        $orderingIssues = $this->migrationOrderingIssues($manifests, $moduleRoots);
        $duplicateMigrationNames = $this->duplicateMigrationNames($this->migrationPaths());
        $cycle = $this->moduleDependencyCycle($manifests, $moduleRoots);

        if ($dependencyIssues === [] && $orderingIssues === [] && $duplicateMigrationNames === [] && $cycle === []) {
            return;
        }

        throw new ModuleManifestException($this->failureMessage($dependencyIssues, $orderingIssues, $duplicateMigrationNames, $cycle));
    }

    private function reader(): ModuleManifestReader
    {
        return new ModuleManifestReader([
            app_path('Base'),
            app_path('Modules'),
            base_path('extensions'),
        ]);
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function sortPathsByModuleGraph(array $paths): array
    {
        $reader = $this->reader();
        $moduleRoots = $reader->moduleRoots();
        $moduleOrder = array_flip(array_values(array_unique([
            ...$this->topologicalModuleOrder($reader->all(), $moduleRoots),
            ...array_keys($moduleRoots),
        ])));

        usort($paths, function (string $left, string $right) use ($moduleRoots, $moduleOrder): int {
            $leftModule = $this->moduleIdForMigrationPath($left, $moduleRoots);
            $rightModule = $this->moduleIdForMigrationPath($right, $moduleRoots);

            return [
                $moduleOrder[$leftModule] ?? PHP_INT_MAX,
                $leftModule ?? '',
                $left,
            ] <=> [
                $moduleOrder[$rightModule] ?? PHP_INT_MAX,
                $rightModule ?? '',
                $right,
            ];
        });

        return $paths;
    }

    /**
     * @param  list<ModuleManifest>  $manifests
     * @param  array<string, string>  $moduleRoots
     * @return list<array{requiring: string, required: string, requiring_migration: string, required_migration: string}>
     */
    private function migrationOrderingIssues(array $manifests, array $moduleRoots): array
    {
        $migrationsByModule = $this->migrationNamesByModule($moduleRoots);
        $issues = [];

        foreach ($manifests as $manifest) {
            if ($manifest->module === '' || ! isset($migrationsByModule[$manifest->module])) {
                continue;
            }

            foreach (array_keys($manifest->requiresModules) as $required) {
                if (! isset($migrationsByModule[$required])) {
                    continue;
                }

                $requiringMigrations = $migrationsByModule[$manifest->module];
                $requiredMigrations = $migrationsByModule[$required];
                $earliestRequiring = $requiringMigrations[0];
                $latestRequired = $requiredMigrations[array_key_last($requiredMigrations)];

                if (strcmp($latestRequired, $earliestRequiring) >= 0) {
                    $issues[] = [
                        'requiring' => $manifest->module,
                        'required' => $required,
                        'requiring_migration' => $earliestRequiring,
                        'required_migration' => $latestRequired,
                    ];
                }
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, string>  $moduleRoots
     * @return array<string, list<string>>
     */
    private function migrationNamesByModule(array $moduleRoots): array
    {
        $migrations = [];

        foreach ($moduleRoots as $module => $root) {
            $files = glob($root.'/Database/Migrations/*_*.php') ?: [];

            if ($files === []) {
                continue;
            }

            $migrations[$module] = array_map(
                fn (string $file): string => basename($file, '.php'),
                $files,
            );

            sort($migrations[$module]);
        }

        return $migrations;
    }

    /**
     * @param  list<string>  $paths
     * @return array<string, list<string>>
     */
    private function duplicateMigrationNames(array $paths): array
    {
        $filesByName = [];

        foreach ($paths as $path) {
            foreach (glob($path.'/*_*.php') ?: [] as $file) {
                $filesByName[basename($file, '.php')][] = $file;
            }
        }

        return array_filter(
            $filesByName,
            fn (array $files): bool => count($files) > 1,
        );
    }

    /**
     * @param  list<ModuleManifest>  $manifests
     * @param  array<string, string>  $moduleRoots
     * @return list<string>
     */
    private function topologicalModuleOrder(array $manifests, array $moduleRoots): array
    {
        $modules = array_keys($moduleRoots);
        $outbound = array_fill_keys($modules, []);
        $inDegree = array_fill_keys($modules, 0);

        foreach ($manifests as $manifest) {
            if ($manifest->module === '' || ! isset($moduleRoots[$manifest->module])) {
                continue;
            }

            foreach (array_keys($manifest->requiresModules) as $required) {
                if (! isset($moduleRoots[$required])) {
                    continue;
                }

                $outbound[$required][] = $manifest->module;
                $inDegree[$manifest->module]++;
            }
        }

        $queue = array_values(array_filter($modules, fn (string $module): bool => $inDegree[$module] === 0));
        sort($queue);
        $ordered = [];

        while ($queue !== []) {
            $module = array_shift($queue);
            $ordered[] = $module;

            foreach ($outbound[$module] as $dependent) {
                if (--$inDegree[$dependent] === 0) {
                    $queue[] = $dependent;
                    sort($queue);
                }
            }
        }

        return $ordered;
    }

    /**
     * @param  list<ModuleManifest>  $manifests
     * @param  array<string, string>  $moduleRoots
     * @return list<string>
     */
    private function moduleDependencyCycle(array $manifests, array $moduleRoots): array
    {
        $ordered = $this->topologicalModuleOrder($manifests, $moduleRoots);
        $orderedSet = array_fill_keys($ordered, true);

        return array_values(array_filter(
            array_keys($moduleRoots),
            fn (string $module): bool => ! isset($orderedSet[$module]),
        ));
    }

    /**
     * @param  array<string, string>  $moduleRoots
     */
    private function moduleIdForMigrationPath(string $migrationPath, array $moduleRoots): ?string
    {
        $moduleRoot = $this->normalizePath(dirname($migrationPath, 2));

        foreach ($moduleRoots as $module => $root) {
            if ($this->normalizePath($root) === $moduleRoot) {
                return $module;
            }
        }

        return null;
    }

    /**
     * @param  list<array{issue: 'missing'|'incompatible', requiring: string, requiring_module: string, required: string, constraint: string, installed_version?: string}>  $dependencyIssues
     * @param  list<array{requiring: string, required: string, requiring_migration: string, required_migration: string}>  $orderingIssues
     * @param  array<string, list<string>>  $duplicateMigrationNames
     * @param  list<string>  $cycle
     */
    private function failureMessage(array $dependencyIssues, array $orderingIssues, array $duplicateMigrationNames, array $cycle): string
    {
        $lines = ['Module migration dependency preflight failed.'];

        foreach ($dependencyIssues as $issue) {
            if ($issue['issue'] === 'missing') {
                $lines[] = sprintf(
                    '- %s requires %s (%s), but that module is not installed or enabled.',
                    $issue['requiring'],
                    $issue['required'],
                    $issue['constraint'],
                );

                continue;
            }

            $lines[] = sprintf(
                '- %s requires %s (%s), but installed version is %s.',
                $issue['requiring'],
                $issue['required'],
                $issue['constraint'],
                $issue['installed_version'] !== '' ? $issue['installed_version'] : 'unversioned',
            );
        }

        foreach ($orderingIssues as $issue) {
            $lines[] = sprintf(
                '- %s requires %s, but %s would run before required migration %s. Rename migrations so the required module sorts first.',
                $issue['requiring'],
                $issue['required'],
                $issue['requiring_migration'],
                $issue['required_migration'],
            );
        }

        foreach ($duplicateMigrationNames as $migration => $files) {
            $lines[] = sprintf(
                '- Duplicate migration name %s appears in: %s. Laravel keeps one file per migration name, so rename one of them.',
                $migration,
                implode(', ', $files),
            );
        }

        if ($cycle !== []) {
            $lines[] = '- Module manifest dependency cycle: '.implode(' -> ', $cycle).'.';
        }

        return implode(PHP_EOL, $lines);
    }

    private function normalizePath(string $path): string
    {
        return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    }
}
