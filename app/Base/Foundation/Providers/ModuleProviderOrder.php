<?php

namespace App\Base\Foundation\Providers;

use App\Base\Foundation\ApplicationTopology;
use App\Base\Foundation\ModuleManifest\ModuleManifest;
use App\Base\Foundation\ModuleManifest\ModuleManifestException;
use App\Base\Foundation\ModuleManifest\ModuleManifestReader;
use App\Base\Support\AppPath;
use Illuminate\Support\ServiceProvider;

final class ModuleProviderOrder
{
    /** @param list<class-string<ServiceProvider>> $providers
     * @return list<class-string<ServiceProvider>>
     */
    public function sort(array $providers): array
    {
        $reader = new ModuleManifestReader(array_map(base_path(...), ApplicationTopology::relativeRoots()));
        $manifests = $reader->all();
        $roots = $this->orderedModuleRoots($reader->moduleRoots());
        $rootRanks = array_flip(ApplicationTopology::relativeRoots());

        $this->assertResolvableDependencies($reader, $manifests);

        $graph = $this->dependencyGraph($manifests, $roots, $rootRanks);
        $providerPositions = $this->providerPositions($roots, $this->topologicalOrder($graph));

        usort($providers, static fn (string $left, string $right): int => $providerPositions[$left] <=> $providerPositions[$right]);

        return $providers;
    }

    /**
     * @param  array<string, string>  $roots
     * @return array<string, string>
     */
    private function orderedModuleRoots(array $roots): array
    {
        $rootRanks = array_flip(ApplicationTopology::relativeRoots());

        uasort($roots, static fn (string $left, string $right): int => [
            $rootRanks[ApplicationTopology::rootFor($left)], $left,
        ] <=> [
            $rootRanks[ApplicationTopology::rootFor($right)], $right,
        ]);

        return $roots;
    }

    /** @param  array<int, ModuleManifest>  $manifests */
    private function assertResolvableDependencies(ModuleManifestReader $reader, array $manifests): void
    {
        foreach ($reader->dependencyIssues($manifests) as $issue) {
            throw new ModuleManifestException(sprintf(
                'Cannot resolve provider order: %s requires %s (%s; constraint %s).',
                $issue['requiring_module'] ?: $issue['requiring'],
                $issue['required'],
                $issue['issue'],
                $issue['constraint'],
            ));
        }
    }

    /**
     * Providerless modules stay in the graph: their dependencies can constrain
     * the boot order of a module that does publish a provider.
     *
     * @param  array<int, ModuleManifest>  $manifests
     * @param  array<string, string>  $roots
     * @param  array<string, int>  $rootRanks
     * @return array<string, list<string>>
     */
    private function dependencyGraph(array $manifests, array $roots, array $rootRanks): array
    {
        $idsByPath = array_flip(array_map(static fn (string $path): string => str_replace('\\', '/', $path), $roots));
        $remaining = array_fill_keys(array_keys($roots), []);

        foreach ($manifests as $manifest) {
            $module = $idsByPath[str_replace('\\', '/', $manifest->path)];
            foreach (array_keys($manifest->requiresModules) as $required) {
                $this->assertDependencyDoesNotInvertRootOrder($module, $required, $roots, $rootRanks);
                $remaining[$module][] = $required;
            }
        }

        return $remaining;
    }

    /**
     * @param  array<string, string>  $roots
     * @param  array<string, int>  $rootRanks
     */
    private function assertDependencyDoesNotInvertRootOrder(
        string $module,
        string $required,
        array $roots,
        array $rootRanks,
    ): void {
        if ($rootRanks[ApplicationTopology::rootFor($roots[$required])]
            <= $rootRanks[ApplicationTopology::rootFor($roots[$module])]) {
            return;
        }

        throw new ModuleManifestException("$module cannot require later-root module $required.");
    }

    /**
     * @param  array<string, list<string>>  $remaining
     * @return list<string>
     */
    private function topologicalOrder(array $remaining): array
    {
        $ordered = [];
        while ($remaining !== []) {
            $ready = $this->readyModule($remaining);
            if ($ready === null) {
                throw new ModuleManifestException('Module dependency cycle: '.$this->cycle($remaining).'.');
            }

            $ordered[] = $ready;
            unset($remaining[$ready]);
        }

        return $ordered;
    }

    /** @param  array<string, list<string>>  $remaining */
    private function readyModule(array $remaining): ?string
    {
        foreach ($remaining as $module => $requires) {
            if (array_intersect($requires, array_keys($remaining)) === []) {
                return $module;
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $roots
     * @param  list<string>  $ordered
     * @return array<class-string<ServiceProvider>, int>
     */
    private function providerPositions(array $roots, array $ordered): array
    {
        $positions = array_flip($ordered);
        $providerPositions = [];
        foreach ($roots as $module => $path) {
            $providerPositions[AppPath::toClass($path.'/ServiceProvider.php')] = $positions[$module];
        }

        return $providerPositions;
    }

    /** @param  array<string, list<string>>  $remaining */
    private function cycle(array $remaining): string
    {
        $path = [];
        $seen = [];
        $module = array_key_first($remaining);
        while (! isset($seen[$module])) {
            $seen[$module] = count($path);
            $path[] = $module;
            // Every remaining node has an unresolved dependency, otherwise
            // the stable ready-node pass above would have selected it.
            $module = array_values(array_intersect($remaining[$module], array_keys($remaining)))[0];
        }

        return implode(' -> ', [...array_slice($path, $seen[$module]), $module]);
    }
}
