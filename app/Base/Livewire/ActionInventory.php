<?php

namespace App\Base\Livewire;

use App\Base\Foundation\ApplicationTopology;
use App\Base\Foundation\Services\DomainState;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\Features\SupportComputed\BaseComputed;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Finder\Finder;

final class ActionInventory
{
    public function __construct(private ComponentDiscoveryService $discovery) {}

    /**
     * Lexical references are search leads, never proof of exercised behavior.
     *
     * @return list<array{component: string, method: string, module_owned: bool, referenced_in_tests: bool, test_files: list<string>}>
     */
    public function scan(?string $domain = null): array
    {
        if ($domain !== null && (! preg_match('/^[A-Z][A-Za-z0-9]*$/', $domain)
            || ! is_dir(ApplicationTopology::domainPath($domain)) || DomainState::isDisabled($domain))) {
            throw new ActionInventoryException('Unknown or disabled Domain: '.$domain);
        }

        $rows = [];
        foreach (array_unique($this->discovery->discover()) as $class) {
            if ($domain !== null && ! str_starts_with($class, 'App\\Domains\\'.$domain.'\\')) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            $module = strstr(str_replace('\\', '/', $reflection->getFileName()), '/Livewire/', true);
            $blocked = $this->lifecycleMethods($class);
            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isStatic() || str_starts_with($method->name, '__')
                    || in_array($method->getDeclaringClass()->name, [Component::class, 'Livewire\\Volt\\Component'], true)
                    || Str::is($blocked, $method->name)
                    || $method->getAttributes(BaseComputed::class, ReflectionAttribute::IS_INSTANCEOF) !== []) {
                    continue;
                }

                $file = str_replace('\\', '/', $method->getFileName());
                $rows[] = [
                    'component' => $class,
                    'method' => $method->name,
                    'module_owned' => $module !== false && str_starts_with($file, $module.'/'),
                    'referenced_in_tests' => false,
                    'test_files' => [],
                ];
            }
        }

        $references = $this->testReferences(array_fill_keys(array_column($rows, 'method'), true));
        foreach ($rows as &$row) {
            $row['test_files'] = $references[$row['method']] ?? [];
            $row['referenced_in_tests'] = $row['test_files'] !== [];
        }
        unset($row);

        usort($rows, fn (array $left, array $right): int => [$left['component'], $left['method']] <=> [$right['component'], $right['method']]);

        return $rows;
    }

    /** @return list<string> */
    private function lifecycleMethods(string $class): array
    {
        // Match Livewire's direct-call exclusions; never construct a component
        // or invoke its hooks to discover an action.
        $blocked = ['render', 'mount', 'boot', 'booted', 'exception', 'hydrate*', 'dehydrate*',
            'updating*', 'updated*', 'rendering', 'rendered', 'scriptSrc'];
        foreach (class_uses_recursive($class) as $trait) {
            foreach (['mount', 'boot', 'booted'] as $prefix) {
                $blocked[] = $prefix.class_basename($trait);
            }
        }

        return $blocked;
    }

    /** @param array<string, true> $methods
     * @return array<string, list<string>>
     */
    private function testReferences(array $methods): array
    {
        if ($methods === []) {
            return [];
        }

        $directories = [base_path('tests')];
        foreach ([
            ApplicationTopology::baseComponentPattern('Tests'),
            ApplicationTopology::coreModulePattern('Tests'),
            ApplicationTopology::domainModulePattern('Tests'),
            ApplicationTopology::extensionModulePattern('Tests'),
        ] as $pattern) {
            array_push($directories, ...DomainState::filterPaths(glob($pattern, GLOB_ONLYDIR) ?: []));
        }

        $references = [];
        foreach ((new Finder)->files()->name('*.php')->followLinks()->in($directories)->sortByName() as $file) {
            preg_match_all('/\b[A-Za-z_][A-Za-z0-9_]*\b/', $file->getContents(), $tokens);
            foreach (array_intersect_key(array_fill_keys($tokens[0], true), $methods) as $method => $_) {
                $references[$method][] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }

        foreach ($references as &$files) {
            $files = array_values(array_unique($files));
            sort($files);
        }

        return $references;
    }
}
