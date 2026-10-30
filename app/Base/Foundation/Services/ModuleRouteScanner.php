<?php

namespace App\Base\Foundation\Services;

/** Reads Module route declarations without booting their route files. */
final class ModuleRouteScanner
{
    /**
     * @param  list<string>  $modules
     * @param  array<string, string>  $roots
     * @return list<array{module: string, key: string, name: string}>
     */
    public function declarations(array $modules, array $roots): array
    {
        $declared = [];

        foreach ($modules as $module) {
            $path = $roots[$module] ?? null;
            if ($path === null) {
                continue;
            }

            foreach (['web', 'api'] as $type) {
                $file = $path.DIRECTORY_SEPARATOR.'Routes'.DIRECTORY_SEPARATOR.$type.'.php';
                if (is_file($file)) {
                    $declared = [...$declared, ...$this->parse($module, $type, (string) file_get_contents($file))];
                }
            }
        }

        return $declared;
    }

    /**
     * @param  list<string>  $modules
     * @param  array<string, string>  $roots
     * @return list<string>
     */
    public function collisions(array $modules, array $roots): array
    {
        $byKey = [];
        $byName = [];
        $refusals = [];

        foreach ($this->declarations($modules, $roots) as $route) {
            $keyOwner = $byKey[$route['key']] ?? null;
            if ($keyOwner !== null && $keyOwner !== $route['module']) {
                $refusals[] = sprintf('collision: route %s is registered by %s and %s', $route['key'], $keyOwner, $route['module']);
            }
            $byKey[$route['key']] ??= $route['module'];

            if ($route['name'] === '') {
                continue;
            }

            $nameOwner = $byName[$route['name']] ?? null;
            if ($nameOwner !== null && $nameOwner !== $route['module']) {
                $refusals[] = sprintf('collision: route name %s is registered by %s and %s', $route['name'], $nameOwner, $route['module']);
            }
            $byName[$route['name']] ??= $route['module'];
        }

        return $refusals;
    }

    /** @return list<array{module: string, key: string, name: string}> */
    private function parse(string $module, string $type, string $contents): array
    {
        if (! preg_match_all('/Route::(get|post|put|patch|delete|options|any)\(\s*[\'\"]([^\'\"]+)[\'\"]([^;]*);/i', $contents, $matches, PREG_SET_ORDER)) {
            return [];
        }

        return array_map(fn (array $match): array => $this->declaration($module, $type, $match), $matches);
    }

    /**
     * @param  array{0: string, 1: string, 2: string, 3: string}  $match
     * @return array{module: string, key: string, name: string}
     */
    private function declaration(string $module, string $type, array $match): array
    {
        preg_match('/->name\(\s*[\'\"]([^\'\"]+)[\'\"]\s*\)/', $match[3], $nameMatch);

        return [
            'module' => $module,
            'key' => strtoupper($match[1]).' /'.($type === 'api' ? 'api/' : '').ltrim($match[2], '/'),
            'name' => $nameMatch[1] ?? '',
        ];
    }
}
