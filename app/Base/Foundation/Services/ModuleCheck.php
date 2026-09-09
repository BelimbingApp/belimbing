<?php

namespace App\Base\Foundation\Services;

use App\Base\Authz\Capability\CapabilityCatalog;
use App\Base\Authz\Capability\CapabilityInventory;
use App\Base\Foundation\ApplicationTopology;
use App\Base\Foundation\ModuleManifest\ModuleManifest;
use App\Base\Foundation\ModuleManifest\ModuleManifestReader;
use App\Base\Support\AppPath;
use Illuminate\Contracts\Foundation\Application;

/**
 * Local smoke check for one Module: compose it with its declared
 * requires-modules, surface dependency and ownership refusals, and list the
 * routes, tables, and container bindings that composition contributes.
 *
 * This does not reboot Laravel with a filtered provider list. Base and Core
 * stay whatever the host process already loaded; the report scopes discovery
 * to the named Module's dependency closure so authors can run one command
 * before opening a PR (#657).
 */
final class ModuleCheck
{
    private const EMPTY_REPORT_LINE = '  (none)';

    public function __construct(
        private readonly Application $app,
        private readonly ModuleTableOwnershipScanner $tableOwnership,
        private readonly CapabilityInventory $capabilityInventory,
    ) {}

    /**
     * @return array{
     *     module: string,
     *     composition: list<string>,
     *     refusals: list<string>,
     *     routes: list<string>,
     *     tables: list<string>,
     *     bindings: list<array{abstract: string, resolved: bool}>,
     *     capabilities: array{accepted: list<string>, rejected: array<string, string>},
     *     ok: bool
     * }
     */
    public function inspect(string $moduleId): array
    {
        $moduleId = trim($moduleId);
        $reader = $this->reader();
        $roots = $reader->moduleRoots();
        $manifestsById = [];

        foreach ($reader->all() as $manifest) {
            if ($manifest->module !== '') {
                $manifestsById[$manifest->module] = $manifest;
            }
        }

        if ($moduleId === '' || ! isset($roots[$moduleId])) {
            return [
                'module' => $moduleId,
                'composition' => [],
                'refusals' => [sprintf('unknown module: %s', $moduleId === '' ? '(empty)' : $moduleId)],
                'routes' => [],
                'tables' => [],
                'bindings' => [],
                'capabilities' => ['accepted' => [], 'rejected' => []],
                'ok' => false,
            ];
        }

        [$composition, $refusals] = $this->compositionClosure($moduleId, $manifestsById, $roots);
        $scopedManifests = array_values(array_filter(
            array_map(fn (string $id): ?ModuleManifest => $manifestsById[$id] ?? null, $composition),
        ));

        foreach ($reader->dependencyIssues($scopedManifests) as $issue) {
            $refusals[] = sprintf(
                'dependency: %s requires %s (%s; constraint %s)',
                $issue['requiring_module'] !== '' ? $issue['requiring_module'] : $issue['requiring'],
                $issue['required'],
                $issue['issue'],
                $issue['constraint'],
            );
        }

        $refusals = [...$refusals, ...$this->graphRefusals($scopedManifests, $roots)];
        $refusals = [...$refusals, ...$this->tableCollisions($composition, $roots)];
        $refusals = [...$refusals, ...$this->routeCollisions($composition, $roots)];
        $capabilities = $this->capabilitiesFor($roots[$moduleId]);
        foreach ($capabilities['rejected'] as $capability => $reason) {
            $refusals[] = sprintf('capability: %s (%s)', $capability, $reason);
        }
        $refusals = array_values(array_unique($refusals));
        sort($refusals);

        $routes = $this->routesFor($composition, $roots);
        $tables = $this->tablesFor($composition, $roots);
        $bindings = $this->bindingsFor($composition, $roots);

        return [
            'module' => $moduleId,
            'composition' => $composition,
            'refusals' => $refusals,
            'routes' => $routes,
            'tables' => $tables,
            'bindings' => $bindings,
            'capabilities' => $capabilities,
            'ok' => $refusals === [],
        ];
    }

    /**
     * Inspect ownership across every enabled Domain Module in the composed app.
     *
     * A single-module smoke check only sees that Module's dependency closure.
     * This wider view catches an unrelated pinned Module claiming the same
     * table, route key, or route name before migration or route registration.
     *
     * @return array{
     *     modules: list<string>,
     *     refusals: list<string>,
     *     ok: bool
     * }
     */
    public function inspectDomainOwnership(): array
    {
        $roots = $this->reader()->moduleRoots();
        $modules = array_keys(array_filter(
            $roots,
            fn (string $path): bool => ApplicationTopology::belongsToRoot($path, ApplicationTopology::DOMAINS),
        ));
        sort($modules);

        $refusals = [
            ...$this->tableCollisions($modules, $roots),
            ...$this->routeCollisions($modules, $roots),
        ];
        $refusals = array_values(array_unique($refusals));
        sort($refusals);

        return [
            'modules' => $modules,
            'refusals' => $refusals,
            'ok' => $refusals === [],
        ];
    }

    /**
     * Stable plain-text report for humans and snapshot tests.
     *
     * @param  array{
     *     module: string,
     *     composition: list<string>,
     *     refusals: list<string>,
     *     routes: list<string>,
     *     tables: list<string>,
     *     bindings: list<array{abstract: string, resolved: bool}>,
     *     capabilities: array{accepted: list<string>, rejected: array<string, string>},
     *     ok: bool
     * }  $report
     */
    public function render(array $report): string
    {
        $lines = [
            'module: '.$report['module'],
            'composition:',
        ];

        if ($report['composition'] === []) {
            $lines[] = self::EMPTY_REPORT_LINE;
        } else {
            foreach ($report['composition'] as $id) {
                $lines[] = '  - '.$id;
            }
        }

        $lines[] = 'refusals:';
        if ($report['refusals'] === []) {
            $lines[] = self::EMPTY_REPORT_LINE;
        } else {
            foreach ($report['refusals'] as $refusal) {
                $lines[] = '  - '.$refusal;
            }
        }

        $lines[] = 'routes:';
        if ($report['routes'] === []) {
            $lines[] = self::EMPTY_REPORT_LINE;
        } else {
            foreach ($report['routes'] as $route) {
                $lines[] = '  - '.$route;
            }
        }

        $lines[] = 'tables:';
        if ($report['tables'] === []) {
            $lines[] = self::EMPTY_REPORT_LINE;
        } else {
            foreach ($report['tables'] as $table) {
                $lines[] = '  - '.$table;
            }
        }

        $lines[] = 'bindings:';
        if ($report['bindings'] === []) {
            $lines[] = self::EMPTY_REPORT_LINE;
        } else {
            foreach ($report['bindings'] as $binding) {
                $lines[] = '  - '.$binding['abstract'].' ['.($binding['resolved'] ? 'resolved' : 'missing').']';
            }
        }

        if ($report['capabilities']['accepted'] !== [] || $report['capabilities']['rejected'] !== []) {
            $lines[] = 'Capabilities:';
            $lines[] = '  accepted: '.count($report['capabilities']['accepted']);
            $lines[] = '  rejected: '.count($report['capabilities']['rejected']);
            foreach ($report['capabilities']['rejected'] as $capability => $reason) {
                $lines[] = '  - '.$capability.': '.$reason;
            }
        }

        $lines[] = 'status: '.($report['ok'] ? 'ok' : 'refused');

        return implode("\n", $lines)."\n";
    }

    /**
     * Stable plain-text ownership report for humans and CI logs.
     *
     * @param  array{modules: list<string>, refusals: list<string>, ok: bool}  $report
     */
    public function renderDomainOwnership(array $report): string
    {
        $lines = ['domain modules:'];

        if ($report['modules'] === []) {
            $lines[] = self::EMPTY_REPORT_LINE;
        } else {
            foreach ($report['modules'] as $module) {
                $lines[] = '  - '.$module;
            }
        }

        $lines[] = 'refusals:';
        if ($report['refusals'] === []) {
            $lines[] = self::EMPTY_REPORT_LINE;
        } else {
            foreach ($report['refusals'] as $refusal) {
                $lines[] = '  - '.$refusal;
            }
        }

        $lines[] = 'status: '.($report['ok'] ? 'ok' : 'refused');

        return implode("\n", $lines)."\n";
    }

    private function reader(): ModuleManifestReader
    {
        return new ModuleManifestReader([
            ApplicationTopology::baseRoot(),
            ApplicationTopology::coreRoot(),
            ApplicationTopology::domainsRoot(),
            ApplicationTopology::extensionsRoot(),
        ]);
    }

    /** @return array{accepted: list<string>, rejected: array<string, string>} */
    private function capabilitiesFor(string $moduleRoot): array
    {
        $module = str_replace('\\', '/', substr($moduleRoot, strlen(app_path()) + 1));
        $declared = array_keys(array_filter(
            $this->capabilityInventory->declarations(),
            static fn (array $modules): bool => in_array($module, $modules, true),
        ));
        sort($declared);

        if ($declared === []) {
            return ['accepted' => [], 'rejected' => []];
        }

        $catalog = CapabilityCatalog::fromConfig((array) config('authz'));
        $catalog->validate();
        $accepted = array_fill_keys($catalog->capabilities(), true);
        $rejected = $catalog->rejected();
        $report = ['accepted' => [], 'rejected' => []];

        foreach ($declared as $capability) {
            if (isset($accepted[$capability])) {
                $report['accepted'][] = $capability;
            } else {
                $report['rejected'][$capability] = $rejected[$capability] ?? 'absent from the configured capability catalog';
            }
        }

        return $report;
    }

    /**
     * @param  array<string, ModuleManifest>  $manifestsById
     * @param  array<string, string>  $roots
     * @return array{0: list<string>, 1: list<string>}
     */
    private function compositionClosure(string $moduleId, array $manifestsById, array $roots): array
    {
        $ordered = [];
        $pending = [$moduleId];
        $seen = [];
        $refusals = [];

        while ($pending !== []) {
            $current = array_shift($pending);
            if (isset($seen[$current])) {
                continue;
            }
            $seen[$current] = true;

            if (! isset($roots[$current])) {
                // The requiring module stays in composition; dependencyIssues
                // names the missing edge. Do not invent a second refusal here.
                continue;
            }

            $ordered[] = $current;
            $manifest = $manifestsById[$current] ?? null;
            if ($manifest === null) {
                continue;
            }

            foreach (array_keys($manifest->requiresModules) as $required) {
                if (! isset($seen[$required])) {
                    $pending[] = $required;
                }
            }
        }

        sort($ordered);

        return [$ordered, $refusals];
    }

    /**
     * @param  list<ModuleManifest>  $manifests
     * @param  array<string, string>  $roots
     * @return list<string>
     */
    private function graphRefusals(array $manifests, array $roots): array
    {
        $refusals = [];
        $rootRanks = array_flip(ApplicationTopology::relativeRoots());
        $remaining = [];

        foreach ($manifests as $manifest) {
            if ($manifest->module === '' || ! isset($roots[$manifest->module])) {
                continue;
            }

            $remaining[$manifest->module] = [];
            foreach (array_keys($manifest->requiresModules) as $required) {
                if (! isset($roots[$required])) {
                    continue;
                }

                $moduleRoot = ApplicationTopology::rootFor($roots[$manifest->module]);
                $requiredRoot = ApplicationTopology::rootFor($roots[$required]);
                if ($moduleRoot !== null && $requiredRoot !== null
                    && ($rootRanks[$requiredRoot] ?? -1) > ($rootRanks[$moduleRoot] ?? -1)) {
                    $refusals[] = sprintf(
                        'dependency: %s cannot require later-root module %s',
                        $manifest->module,
                        $required,
                    );

                    continue;
                }

                $remaining[$manifest->module][] = $required;
            }
        }

        while ($remaining !== []) {
            $ready = null;
            foreach ($remaining as $module => $requires) {
                if (array_intersect($requires, array_keys($remaining)) === []) {
                    $ready = $module;
                    break;
                }
            }

            if ($ready === null) {
                $refusals[] = 'dependency: module dependency cycle: '.$this->cycle($remaining);

                break;
            }

            unset($remaining[$ready]);
        }

        return $refusals;
    }

    /**
     * @param  array<string, list<string>>  $remaining
     */
    private function cycle(array $remaining): string
    {
        $path = [];
        $seen = [];
        $module = array_key_first($remaining);
        while (! isset($seen[$module])) {
            $seen[$module] = count($path);
            $path[] = $module;
            $next = array_values(array_intersect($remaining[$module], array_keys($remaining)));
            $module = $next[0] ?? $module;
            if ($next === []) {
                break;
            }
        }

        return implode(' -> ', [...array_slice($path, $seen[$module] ?? 0), $module]);
    }

    /**
     * @param  list<string>  $composition
     * @param  array<string, string>  $roots
     * @return list<string>
     */
    private function tableCollisions(array $composition, array $roots): array
    {
        $refusals = [];
        $scopedRoots = array_intersect_key($roots, array_flip($composition));

        foreach ($this->tableOwnership->scan($scopedRoots) as $table => $owners) {
            $prior = $owners[0] ?? null;
            if ($prior === null) {
                continue;
            }

            foreach (array_slice($owners, 1) as $module) {
                $refusals[] = sprintf(
                    'collision: table %s is created by %s and %s',
                    $table,
                    $prior,
                    $module,
                );
            }
        }

        return $refusals;
    }

    /**
     * @param  list<string>  $composition
     * @param  array<string, string>  $roots
     * @return list<string>
     */
    private function routeCollisions(array $composition, array $roots): array
    {
        $byKey = [];
        $byName = [];
        $refusals = [];

        foreach ($this->routeDeclarations($composition, $roots) as $route) {
            $keyOwner = $byKey[$route['key']] ?? null;
            if ($keyOwner !== null && $keyOwner !== $route['module']) {
                $refusals[] = sprintf(
                    'collision: route %s is registered by %s and %s',
                    $route['key'],
                    $keyOwner,
                    $route['module'],
                );
            }
            $byKey[$route['key']] ??= $route['module'];

            if ($route['name'] === '') {
                continue;
            }

            $nameOwner = $byName[$route['name']] ?? null;
            if ($nameOwner !== null && $nameOwner !== $route['module']) {
                $refusals[] = sprintf(
                    'collision: route name %s is registered by %s and %s',
                    $route['name'],
                    $nameOwner,
                    $route['module'],
                );
            }
            $byName[$route['name']] ??= $route['module'];
        }

        return $refusals;
    }

    /**
     * @param  list<string>  $composition
     * @param  array<string, string>  $roots
     * @return list<string>
     */
    private function routesFor(array $composition, array $roots): array
    {
        $routes = [];
        foreach ($this->routeDeclarations($composition, $roots) as $route) {
            $label = $route['key'];
            if ($route['name'] !== '') {
                $label .= ' name='.$route['name'];
            }
            $routes[] = $route['module'].': '.$label;
        }
        sort($routes);

        return $routes;
    }

    /**
     * @param  list<string>  $composition
     * @param  array<string, string>  $roots
     * @return list<array{module: string, key: string, name: string}>
     */
    private function routeDeclarations(array $composition, array $roots): array
    {
        $declared = [];

        foreach ($composition as $module) {
            $path = $roots[$module] ?? null;
            if ($path === null) {
                continue;
            }

            foreach (['web', 'api'] as $type) {
                $file = $path.DIRECTORY_SEPARATOR.'Routes'.DIRECTORY_SEPARATOR.$type.'.php';
                if (! is_file($file)) {
                    continue;
                }

                $contents = (string) file_get_contents($file);
                if (! preg_match_all(
                    '/Route::(get|post|put|patch|delete|options|any)\(\s*[\'"]([^\'"]+)[\'"]([^;]*);/i',
                    $contents,
                    $matches,
                    PREG_SET_ORDER,
                )) {
                    continue;
                }

                foreach ($matches as $match) {
                    $method = strtoupper($match[1]);
                    $uri = ($type === 'api' ? 'api/' : '').ltrim($match[2], '/');
                    $name = '';
                    if (preg_match('/->name\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $match[3], $nameMatch) === 1) {
                        $name = $nameMatch[1];
                    }
                    $declared[] = [
                        'module' => $module,
                        'key' => $method.' /'.$uri,
                        'name' => $name,
                    ];
                }
            }
        }

        return $declared;
    }

    /**
     * @param  list<string>  $composition
     * @param  array<string, string>  $roots
     * @return list<string>
     */
    private function tablesFor(array $composition, array $roots): array
    {
        $tables = [];
        $scopedRoots = array_intersect_key($roots, array_flip($composition));

        foreach ($this->tableOwnership->scan($scopedRoots) as $table => $owners) {
            foreach ($owners as $module) {
                $tables[] = $module.': '.$table;
            }
        }

        sort($tables);

        return $tables;
    }

    /**
     * @param  list<string>  $composition
     * @param  array<string, string>  $roots
     * @return list<array{abstract: string, resolved: bool}>
     */
    private function bindingsFor(array $composition, array $roots): array
    {
        $bindings = [];

        foreach ($composition as $module) {
            $path = $roots[$module] ?? null;
            if ($path === null) {
                continue;
            }

            $provider = $path.DIRECTORY_SEPARATOR.'ServiceProvider.php';
            if (! is_file($provider)) {
                continue;
            }

            $contents = (string) file_get_contents($provider);
            if (! preg_match_all(
                '/\$this->app->(?:singleton|bind|bindIf|scoped)\(\s*([^,\)]+)/',
                $contents,
                $matches,
            )) {
                continue;
            }

            foreach ($matches[1] as $raw) {
                $abstract = $this->normalizeBindingAbstract(trim($raw), $provider);
                if ($abstract === null) {
                    continue;
                }

                $bindings[$abstract] = [
                    'abstract' => $abstract,
                    'resolved' => $this->app->bound($abstract),
                ];
            }
        }

        $list = array_values($bindings);
        usort($list, fn (array $left, array $right): int => strcmp($left['abstract'], $right['abstract']));

        return $list;
    }

    private function normalizeBindingAbstract(string $raw, string $providerFile): ?string
    {
        if (preg_match('/^([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)::class$/', $raw, $match) === 1) {
            $class = $match[1];
            if (str_starts_with($class, '\\')) {
                return ltrim($class, '\\');
            }

            if (str_contains($class, '\\')) {
                return $class;
            }

            $providerClass = AppPath::toClass($providerFile);
            $namespace = substr($providerClass, 0, (int) strrpos($providerClass, '\\'));

            return $namespace.'\\'.$class;
        }

        if (preg_match('/^[\'"]([^\'"]+)[\'"]$/', $raw, $match) === 1) {
            return $match[1];
        }

        return null;
    }
}
