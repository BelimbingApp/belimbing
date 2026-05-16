<?php

namespace App\Base\Foundation\ModuleManifest;

/**
 * Scans the BLB module tree for `composer.json` files declaring an
 * `extra.blb` block and returns parsed manifests.
 *
 * Operates on filesystem paths — does not require composer's autoloader
 * to be aware of the modules. Used at boot to verify required-module
 * dependencies and to log missing optional modules.
 *
 * Scope today is the People domain plus the `extensions/` tree. Other
 * domains acquire manifests as they decouple.
 */
class ModuleManifestReader
{
    /**
     * @param  list<string>  $rootPaths  filesystem directories to scan; each
     *                                   one is expected to contain
     *                                   `{Module}/composer.json` immediately
     *                                   below it.
     */
    public function __construct(
        private readonly array $rootPaths,
    ) {}

    /**
     * @return list<ModuleManifest>
     */
    public function all(): array
    {
        $manifests = [];

        foreach ($this->rootPaths as $root) {
            if (! is_dir($root)) {
                continue;
            }

            foreach ((array) glob($root.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'composer.json') as $path) {
                $manifest = $this->parse($path);
                if ($manifest !== null) {
                    $manifests[] = $manifest;
                }
            }
        }

        return $manifests;
    }

    /**
     * Verify that every required-module declared by a manifest is itself
     * present in the loaded set. Returns the list of unmet requirements
     * as ["{manifest-name}" => "{missing-module}"]. Empty array means OK.
     *
     * @param  list<ModuleManifest>  $manifests
     * @return list<array{requiring: string, missing: string}>
     */
    public function verifyRequiredModules(array $manifests): array
    {
        $present = [];
        foreach ($manifests as $m) {
            if ($m->module !== '') {
                $present[$m->module] = true;
            }
        }

        $unmet = [];
        foreach ($manifests as $m) {
            foreach (array_keys($m->requiresModules) as $required) {
                if (! isset($present[$required])) {
                    $unmet[] = [
                        'requiring' => $m->name,
                        'missing' => $required,
                    ];
                }
            }
        }

        return $unmet;
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

        return new ModuleManifest(
            name: $name,
            module: (string) ($blb['module'] ?? ''),
            role: (string) ($blb['role'] ?? 'unknown'),
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
