<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Authz\Controllers;

use App\Base\Authz\Capability\CapabilityKey;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class CapabilityController
{
    /**
     * Show the capabilities page.
     */
    public function index(Request $request): View
    {
        $search = strtolower($request->string('search', '')->toString());
        $filterDomain = $request->string('filter_domain', '')->toString();

        $moduleMap = $this->buildCapabilityModuleMap();

        $capabilities = collect($moduleMap)
            ->map(function (string $module, string $key): object {
                $parts = CapabilityKey::parse($key);

                return (object) [
                    'key' => $key,
                    'domain' => $parts['domain'],
                    'resource' => $parts['resource'],
                    'action' => $parts['action'],
                    'module' => $module,
                ];
            })
            ->when($search !== '', function (Collection $collection) use ($search): Collection {
                return $collection->filter(fn (object $capability): bool => str_contains($capability->key, $search)
                    || str_contains(strtolower($capability->module), $search));
            })
            ->when($filterDomain !== '', function (Collection $collection) use ($filterDomain): Collection {
                return $collection->filter(fn (object $capability): bool => $capability->domain === $filterDomain);
            })
            ->sortBy('key')
            ->values();

        $domains = collect($moduleMap)
            ->keys()
            ->map(fn (string $key): string => CapabilityKey::parse($key)['domain'])
            ->unique()
            ->sort()
            ->values();

        return view('admin.authz.capabilities.index', compact('capabilities', 'domains', 'search', 'filterDomain'));
    }

    /**
     * Return the searchable capabilities table fragment for HTMX requests.
     */
    public function search(Request $request): View
    {
        $search = strtolower($request->string('search', '')->toString());
        $filterDomain = $request->string('filter_domain', '')->toString();

        $moduleMap = $this->buildCapabilityModuleMap();

        $capabilities = collect($moduleMap)
            ->map(function (string $module, string $key): object {
                $parts = CapabilityKey::parse($key);

                return (object) [
                    'key' => $key,
                    'domain' => $parts['domain'],
                    'resource' => $parts['resource'],
                    'action' => $parts['action'],
                    'module' => $module,
                ];
            })
            ->when($search !== '', function (Collection $collection) use ($search): Collection {
                return $collection->filter(fn (object $capability): bool => str_contains($capability->key, $search)
                    || str_contains(strtolower($capability->module), $search));
            })
            ->when($filterDomain !== '', function (Collection $collection) use ($filterDomain): Collection {
                return $collection->filter(fn (object $capability): bool => $capability->domain === $filterDomain);
            })
            ->sortBy('key')
            ->values();

        return view('admin.authz.capabilities.partials.table', compact('capabilities'));
    }

    /**
     * Build a capability-to-module map from authz config files.
     *
     * @return array<string, string>
     */
    private function buildCapabilityModuleMap(): array
    {
        $map = [];
        $patterns = [
            app_path('Base/*/Config/authz.php'),
            app_path('Modules/*/*/Config/authz.php'),
        ];

        foreach ($patterns as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                $moduleConfig = require $file;
                $moduleName = $this->extractModuleName($file);

                foreach ($moduleConfig['capabilities'] ?? [] as $capability) {
                    $map[strtolower($capability)] = $moduleName;
                }
            }
        }

        return $map;
    }

    /**
     * Extract a module name from a config file path.
     *
     * @param  string  $filePath  Absolute path to Config/authz.php
     */
    private function extractModuleName(string $filePath): string
    {
        if (preg_match('#app/Base/([^/]+)/Config/authz\.php$#', $filePath, $matches) === 1) {
            return 'Base / '.$matches[1];
        }

        if (preg_match('#app/Modules/([^/]+)/([^/]+)/Config/authz\.php$#', $filePath, $matches) === 1) {
            return $matches[1].' / '.$matches[2];
        }

        return 'Unknown';
    }
}
