<?php

namespace App\Base\Foundation\ModuleManifest;

use App\Base\Foundation\Services\DomainState;
use App\Base\Support\Str;

/**
 * Scans the BLB module tree for `composer.json` files declaring an
 * `extra.blb` block and returns parsed manifests.
 *
 * Operates on filesystem paths — does not require composer's autoloader
 * to be aware of the modules. Used by the plugin dashboard and the database
 * migration preflight to verify required-module dependencies.
 */
class ModuleManifestReader
{
    /**
     * @param  list<string>  $rootPaths  filesystem directories to scan. A root
     *                                   may be a module root, a one-level module
     *                                   collection (`app/Base`), or a two-level
     *                                   collection (`app/Modules`, `extensions`).
     */
    public function __construct(
        private readonly array $rootPaths,
        private readonly ?ModuleVersionConstraint $versionConstraint = null,
    ) {}

    /**
     * @return list<ModuleManifest>
     */
    public function all(): array
    {
        $manifests = [];

        foreach ($this->manifestPaths() as $path) {
            $manifest = $this->parse($path);
            if ($manifest !== null) {
                $manifests[] = $manifest;
            }
        }

        return $manifests;
    }

    /**
     * Installed module identities keyed to their module root paths.
     *
     * A manifest `extra.blb.module` is authoritative when present. Otherwise
     * BLB falls back to the filesystem identity (`core/company`,
     * `people/payroll`, `base/database`, `vendor/module`) so manifests can
     * require Base/Core modules that have not yet needed their own metadata.
     *
     * @return array<string, string>
     */
    public function moduleRoots(): array
    {
        $roots = [];
        $manifestsByRoot = [];

        foreach ($this->all() as $manifest) {
            $manifestsByRoot[$this->normalizePath($manifest->path)] = $manifest;
        }

        foreach ($this->discoverModuleRoots() as $root) {
            $manifest = $manifestsByRoot[$this->normalizePath($root)] ?? null;
            $module = $manifest !== null && $manifest->module !== ''
                ? $manifest->module
                : $this->conventionalModuleId($root);

            if ($module !== null) {
                $this->rememberModuleRoot($roots, $module, $root);
            }
        }

        ksort($roots);

        return $roots;
    }

    /**
     * Verify that every required-module declared by a manifest is itself
     * present in the loaded set. Returns the list of unmet requirements
     * as rows with requiring manifest and missing module. Empty array means OK.
     *
     * @param  list<ModuleManifest>  $manifests
     * @return list<array{requiring: string, missing: string}>
     */
    public function verifyRequiredModules(array $manifests): array
    {
        return array_values(array_map(
            fn (array $issue): array => [
                'requiring' => $issue['requiring'],
                'missing' => $issue['required'],
            ],
            array_filter(
                $this->dependencyIssues($manifests),
                fn (array $issue): bool => $issue['issue'] === 'missing',
            ),
        ));
    }

    /**
     * Required-module dependency issues, including version incompatibility.
     *
     * @param  list<ModuleManifest>  $manifests
     * @return list<array{issue: 'missing'|'incompatible', requiring: string, requiring_module: string, required: string, constraint: string, installed_version?: string}>
     */
    public function dependencyIssues(array $manifests): array
    {
        $present = $this->moduleRoots();
        $versions = [];

        foreach ($manifests as $manifest) {
            if ($manifest->module !== '') {
                $versions[$manifest->module] = $manifest->version;
            }
        }

        $issues = [];

        foreach ($manifests as $manifest) {
            foreach ($manifest->requiresModules as $required => $constraint) {
                if (! isset($present[$required])) {
                    $issues[] = [
                        'issue' => 'missing',
                        'requiring' => $manifest->name,
                        'requiring_module' => $manifest->module,
                        'required' => $required,
                        'constraint' => $constraint,
                    ];

                    continue;
                }

                $installedVersion = $versions[$required] ?? '';

                if (! $this->versions()->satisfies($installedVersion, $constraint)) {
                    $issues[] = [
                        'issue' => 'incompatible',
                        'requiring' => $manifest->name,
                        'requiring_module' => $manifest->module,
                        'required' => $required,
                        'constraint' => $constraint,
                        'installed_version' => $installedVersion,
                    ];
                }
            }
        }

        return $issues;
    }

    private function parse(string $composerPath): ?ModuleManifest
    {
        $manifestData = $this->manifestData($composerPath);
        if ($manifestData === null) {
            return null;
        }

        ['composer' => $data, 'blb' => $blb] = $manifestData;
        $name = is_string($data['name'] ?? null) ? $data['name'] : '';
        if ($name === '') {
            throw new ModuleManifestException(sprintf('Module manifest at %s has no name.', $composerPath));
        }

        $module = is_string($blb['module'] ?? null) && $blb['module'] !== ''
            ? (string) $blb['module']
            : ($this->conventionalModuleId(dirname($composerPath)) ?? '');

        return new ModuleManifest(
            name: $name,
            module: $module,
            path: dirname($composerPath),
            version: (string) ($blb['version'] ?? ''),
            description: (string) ($blb['description'] ?? ($data['description'] ?? '')),
            requiresModules: $this->normaliseModuleMap($blb['requires-modules'] ?? []),
            optionalModules: $this->normaliseModuleMap($blb['optional-modules'] ?? []),
            publishesEvents: $this->normaliseStringList($blb['publishes-events'] ?? []),
            consumesEvents: $this->normaliseStringList($blb['consumes-events'] ?? []),
        );
    }

    /**
     * @return list<string>
     */
    private function manifestPaths(): array
    {
        $paths = [];

        foreach ($this->discoverModuleRoots() as $root) {
            $path = $root.DIRECTORY_SEPARATOR.'composer.json';

            if (is_file($path)) {
                $paths[] = $path;
            }
        }

        sort($paths);

        return array_values(array_unique($paths));
    }

    /**
     * @return list<string>
     */
    private function discoverModuleRoots(): array
    {
        $roots = [];

        foreach ($this->rootPaths as $root) {
            if (! is_dir($root)) {
                continue;
            }

            foreach ($this->moduleRootCandidates($root) as $candidate) {
                if ($this->looksLikeModuleRoot($candidate)) {
                    $roots[] = $candidate;
                }
            }
        }

        $roots = DomainState::filterPaths(array_values(array_unique($roots)));

        sort($roots);

        return $roots;
    }

    /**
     * @return list<string>
     */
    private function moduleRootCandidates(string $root): array
    {
        return array_values(array_unique(array_merge(
            [$root],
            glob($root.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [],
            glob($root.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [],
        )));
    }

    private function looksLikeModuleRoot(string $path): bool
    {
        return is_file($path.DIRECTORY_SEPARATOR.'composer.json')
            || is_file($path.DIRECTORY_SEPARATOR.'ServiceProvider.php')
            || is_dir($path.DIRECTORY_SEPARATOR.'Database'.DIRECTORY_SEPARATOR.'Migrations');
    }

    private function conventionalModuleId(string $path): ?string
    {
        $relative = $this->relativeBasePath($path);
        $segments = explode('/', trim($relative, '/'));

        if (($segments[0] ?? null) === 'app' && ($segments[1] ?? null) === 'Base' && isset($segments[2])) {
            return 'base/'.$this->pascalSegmentToIdentifier($segments[2]);
        }

        if (($segments[0] ?? null) === 'app' && ($segments[1] ?? null) === 'Modules' && isset($segments[2], $segments[3])) {
            return $this->pascalSegmentToIdentifier($segments[2]).'/'.$this->pascalSegmentToIdentifier($segments[3]);
        }

        if (($segments[0] ?? null) === 'extensions' && isset($segments[1], $segments[2])) {
            return $segments[1].'/'.$segments[2];
        }

        return null;
    }

    private function pascalSegmentToIdentifier(string $segment): string
    {
        if (strtoupper($segment) === $segment) {
            return strtolower($segment);
        }

        return Str::pascalToKebab($segment);
    }

    /**
     * @param  array<string, string>  $roots
     */
    private function rememberModuleRoot(array &$roots, string $module, string $root): void
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR);

        if (isset($roots[$module]) && $this->normalizePath($roots[$module]) !== $this->normalizePath($root)) {
            throw new ModuleManifestException(sprintf(
                'Duplicate BLB module identity [%s] declared by [%s] and [%s].',
                $module,
                $roots[$module],
                $root,
            ));
        }

        $roots[$module] = $root;
    }

    private function relativeBasePath(string $path): string
    {
        return str_replace([base_path().DIRECTORY_SEPARATOR, '\\'], ['', '/'], $path);
    }

    private function normalizePath(string $path): string
    {
        return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    }

    private function versions(): ModuleVersionConstraint
    {
        return $this->versionConstraint ?? new ModuleVersionConstraint;
    }

    /**
     * @return array{composer: array<string, mixed>, blb: array<string, mixed>}|null
     */
    private function manifestData(string $composerPath): ?array
    {
        $contents = @file_get_contents($composerPath);
        if ($contents === false) {
            return null;
        }

        $data = json_decode($contents, true);
        $blb = is_array($data) ? ($data['extra']['blb'] ?? null) : null;
        if (! is_array($data) || ! is_array($blb)) {
            return null;
        }

        return [
            'composer' => $data,
            'blb' => $blb,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function normaliseModuleMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $key => $constraint) {
            if (! is_string($key)) {
                continue;
            }
            $out[$key] = is_string($constraint) ? $constraint : '*';
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function normaliseStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn ($v): string => is_string($v) ? $v : '', $value),
            fn (string $v): bool => $v !== '',
        ));
    }
}
