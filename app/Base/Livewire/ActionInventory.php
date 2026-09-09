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
    private const ALL_COMPONENTS_LABEL = 'all components';

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

    /**
     * Count module-owned actions with no lexical test reference for a Domain.
     *
     * Used by the CI ratchet (#630). Lexical references remain search leads,
     * not proof of exercised behavior — see ActionInventory::scan().
     */
    public function moduleOwnedUnreferencedCount(?string $domain = null): int
    {
        return count($this->moduleOwnedUnreferencedActions($domain));
    }

    /** @return list<string> */
    private function moduleOwnedUnreferencedActions(?string $domain): array
    {
        $actions = [];
        foreach ($this->scan($domain) as $row) {
            if ($row['module_owned'] && ! $row['referenced_in_tests']) {
                $actions[] = $row['component'].'::'.$row['method'];
            }
        }

        return $actions;
    }

    /**
     * @return array{domain: string|null, module_owned_unreferenced: int, actions: list<string>}
     */
    public function baselineSnapshot(?string $domain = null): array
    {
        $actions = $this->moduleOwnedUnreferencedActions($domain);

        return [
            'domain' => $domain,
            'module_owned_unreferenced' => count($actions),
            'actions' => $actions,
        ];
    }

    /**
     * @param  array{domain?: string|null, module_owned_unreferenced?: mixed, actions?: mixed, strict?: bool}  $baseline
     * @return array{ok: bool, current: int, baseline: int, message: string, new_actions?: list<string>|null}
     */
    public function compareToBaseline(array $baseline, ?string $domain = null, bool $strictNames = false): array
    {
        if (! array_key_exists('module_owned_unreferenced', $baseline)
            || ! is_int($baseline['module_owned_unreferenced'])
            || $baseline['module_owned_unreferenced'] < 0) {
            throw new ActionInventoryException('Baseline must include a non-negative integer module_owned_unreferenced.');
        }

        $expectedDomain = $baseline['domain'] ?? null;
        if (is_string($expectedDomain) && $expectedDomain !== '' && $domain !== null && $expectedDomain !== $domain) {
            throw new ActionInventoryException(
                'Baseline domain '.$expectedDomain.' does not match requested Domain '.$domain.'.'
            );
        }

        $actions = $this->moduleOwnedUnreferencedActions($domain);
        $current = count($actions);
        $limit = $baseline['module_owned_unreferenced'];
        $strictNames = $strictNames || ($baseline['strict'] ?? false) === true;
        if ($strictNames && ! array_key_exists('actions', $baseline)) {
            throw new ActionInventoryException('Strict names require an action list; run --write-baseline first.');
        }
        $newActions = null;
        $namesChanged = false;
        if (array_key_exists('actions', $baseline)) {
            if (! is_array($baseline['actions']) || ! array_is_list($baseline['actions'])
                || count(array_filter($baseline['actions'], is_string(...))) !== count($baseline['actions'])) {
                throw new ActionInventoryException('Baseline actions must be a list of component::method strings.');
            }
            $newActions = array_values(array_diff($actions, $baseline['actions']));
            $namesChanged = $newActions !== [] || array_diff($baseline['actions'], $actions) !== [];
        }

        if ($current > $limit || ($strictNames && $namesChanged)) {
            return [
                'ok' => false,
                'current' => $current,
                'baseline' => $limit,
                'new_actions' => $newActions,
                'message' => $strictNames && $namesChanged
                    ? 'Livewire action names changed for '.($domain ?? self::ALL_COMPONENTS_LABEL).'; review the snapshot and update it with --write-baseline.'
                    : sprintf(
                        'Livewire action debt rose for %s: %d module-owned unreferenced actions (baseline %d). Cover new actions or raise only with justification.',
                        $domain ?? self::ALL_COMPONENTS_LABEL,
                        $current,
                        $limit,
                    ),
            ];
        }

        return [
            'ok' => true,
            'current' => $current,
            'baseline' => $limit,
            'message' => sprintf(
                'Livewire action debt for %s is %d (baseline %d).%s',
                $domain ?? self::ALL_COMPONENTS_LABEL,
                $current,
                $limit,
                $current < $limit
                    ? ' Lower the committed baseline in the same PR to lock the improvement.'
                    : '',
            ),
        ];
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
            preg_match_all('/\b[A-Za-z_]\w*\b/', $file->getContents(), $tokens);
            foreach (array_intersect_key(array_fill_keys($tokens[0], true), $methods) as $method => $_) {
                $references[$method][] = $this->relativeTestPath($file->getPathname());
            }
        }

        foreach ($references as &$files) {
            $files = array_values(array_unique($files));
            sort($files);
        }

        return $references;
    }

    private function relativeTestPath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $basePath = rtrim(str_replace('\\', '/', base_path()), '/').'/';

        return str_starts_with($path, $basePath) ? substr($path, strlen($basePath)) : $path;
    }
}
