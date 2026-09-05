<?php

namespace App\Base\Foundation\Providers;

use App\Base\Foundation\ApplicationTopology;
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
        $roots = $reader->moduleRoots();
        $rootRanks = array_flip(ApplicationTopology::relativeRoots());
        uasort($roots, static fn (string $left, string $right): int => [
            $rootRanks[ApplicationTopology::rootFor($left)], $left,
        ] <=> [
            $rootRanks[ApplicationTopology::rootFor($right)], $right,
        ]);

        foreach ($reader->dependencyIssues($manifests) as $issue) {
            throw new ModuleManifestException(sprintf(
                'Cannot resolve provider order: %s requires %s (%s; constraint %s).',
                $issue['requiring_module'] ?: $issue['requiring'],
                $issue['required'], $issue['issue'], $issue['constraint'],
            ));
        }

        $idsByPath = array_flip(array_map(static fn (string $path): string => str_replace('\\', '/', $path), $roots));
        $remaining = array_fill_keys(array_keys($roots), []);
        foreach ($manifests as $manifest) {
            $module = $idsByPath[str_replace('\\', '/', $manifest->path)];
            foreach (array_keys($manifest->requiresModules) as $required) {
                if ($rootRanks[ApplicationTopology::rootFor($roots[$required])]
                    > $rootRanks[ApplicationTopology::rootFor($roots[$module])]) {
                    throw new ModuleManifestException("$module cannot require later-root module $required.");
                }
                $remaining[$module][] = $required;
            }
        }

        // Providerless modules stay in the graph: their dependencies can
        // constrain the boot order of a module that does publish a provider.
        $ordered = [];
        while ($remaining !== []) {
            $ready = null;
            foreach ($remaining as $module => $requires) {
                if (array_intersect($requires, array_keys($remaining)) === []) {
                    $ready = $module;
                    break;
                }
            }
            if ($ready === null) {
                throw new ModuleManifestException('Module dependency cycle: '.$this->cycle($remaining).'.');
            }
            $ordered[] = $ready;
            unset($remaining[$ready]);
        }

        $positions = array_flip($ordered);
        $providerPositions = [];
        foreach ($roots as $module => $path) {
            $providerPositions[AppPath::toClass($path.'/ServiceProvider.php')] = $positions[$module];
        }
        usort($providers, static fn (string $left, string $right): int => $providerPositions[$left] <=> $providerPositions[$right]);

        return $providers;
    }

    /** @param array<string, list<string>> $remaining */
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
