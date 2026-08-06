<?php

namespace App\Base\Dashboard\Services;

use App\Base\Foundation\ApplicationTopology;
use App\Base\Foundation\Services\DomainState;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Discovers dashboard widget definitions from module config files.
 *
 * Mirrors menu discovery: any module that ships a `Config/dashboard.php`
 * returning `['widgets' => [...]]` contributes widgets with no central
 * registration step. Paths belonging to disabled domains are filtered out
 * by DomainState, so widgets vanish with their module.
 */
class WidgetDiscoveryService
{
    /**
     * Glob patterns for dashboard config discovery.
     *
     * Same shape as menu discovery: Base modules, domain anchors, leaf
     * modules, and extensions at both vendor and package level.
     */
    protected function scanPatterns(): array
    {
        return [
            ApplicationTopology::baseComponentPattern('Config/dashboard.php'),
            ApplicationTopology::coreModulePattern('Config/dashboard.php'),
            ApplicationTopology::domainPattern('Config/dashboard.php'),
            ApplicationTopology::domainModulePattern('Config/dashboard.php'),
            ApplicationTopology::extensionSourcePattern('Config/dashboard.php'),
            ApplicationTopology::extensionModulePattern('Config/dashboard.php'),
        ];
    }

    /**
     * Discover all widget definition arrays from configured paths.
     *
     * @return Collection<int, array<string, mixed>> Raw widget arrays, each with a `_source` file path
     */
    public function discover(): Collection
    {
        $widgets = collect();

        foreach ($this->scanPatterns() as $pattern) {
            $files = DomainState::filterPaths(glob($pattern) ?: []);

            foreach ($files as $file) {
                $this->processFile($file, $widgets);
            }
        }

        return $widgets;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $widgets  Collection to add discovered widgets to (mutated)
     */
    protected function processFile(string $path, Collection $widgets): void
    {
        $config = require $path;

        if (! is_array($config) || ! is_array($config['widgets'] ?? null)) {
            Log::warning('Dashboard config file did not return a widgets array', ['file' => $path]);

            return;
        }

        foreach ($config['widgets'] as $widget) {
            if (! is_array($widget)) {
                continue;
            }

            $widget['_source'] = $path;
            $widgets->push($widget);
        }
    }
}
