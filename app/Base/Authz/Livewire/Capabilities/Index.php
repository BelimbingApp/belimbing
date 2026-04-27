<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Authz\Livewire\Capabilities;

use App\Base\Authz\Capability\CapabilityKey;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Index extends Component
{
    public string $search = '';

    public string $filterDomain = '';

    /**
     * Build a capability-to-module mapping by scanning authz config files.
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
     * Extract a human-readable module name from a config file path.
     *
     * @param  string  $filePath  Absolute path to Config/authz.php
     */
    private function extractModuleName(string $filePath): string
    {
        // app/Base/Authz/Config/authz.php → Base / Authz
        if (preg_match('#app/Base/([^/]+)/Config/authz\.php$#', $filePath, $m)) {
            return 'Base / '.$m[1];
        }

        // app/Modules/Core/User/Config/authz.php → Core / User
        if (preg_match('#app/Modules/([^/]+)/([^/]+)/Config/authz\.php$#', $filePath, $m)) {
            return $m[1].' / '.$m[2];
        }

        return 'Unknown';
    }

    public function render(): View
    {
        $moduleMap = $this->buildCapabilityModuleMap();

        $capabilities = collect($moduleMap)
            ->map(function (string $module, string $key) {
                $parts = CapabilityKey::parse($key);

                return (object) [
                    'key' => $key,
                    'domain' => $parts['domain'],
                    'resource' => $parts['resource'],
                    'action' => $parts['action'],
                    'module' => $module,
                ];
            })
            ->when($this->search, function ($collection, $search) {
                $search = strtolower($search);

                return $collection->filter(fn ($cap) => str_contains($cap->key, $search)
                    || str_contains(strtolower($cap->module), $search));
            })
            ->when($this->filterDomain, function ($collection, $domain) {
                return $collection->filter(fn ($cap) => $cap->domain === $domain);
            })
            ->sortBy('key')
            ->values();

        $domains = collect($moduleMap)
            ->keys()
            ->map(fn (string $key) => CapabilityKey::parse($key)['domain'])
            ->unique()
            ->sort()
            ->values();

        return view('livewire.admin.authz.capabilities.index', [
            'capabilities' => $capabilities,
            'domains' => $domains,
        ]);
    }
}
