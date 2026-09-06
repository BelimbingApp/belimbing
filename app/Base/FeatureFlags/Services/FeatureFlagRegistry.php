<?php

namespace App\Base\FeatureFlags\Services;

use App\Base\FeatureFlags\Exceptions\DuplicateFeatureFlagException;
use App\Base\FeatureFlags\Exceptions\UndeclaredFeatureFlagException;
use App\Base\Foundation\ApplicationTopology;
use App\Base\Foundation\ModuleManifest\ModuleManifest;
use App\Base\Foundation\ModuleManifest\ModuleManifestReader;

/**
 * Collects feature-flag declarations from every enabled module manifest.
 *
 * The registry is the discovery surface: callers never invent flag names.
 * Duplicate identities across modules fail closed at build time.
 */
final class FeatureFlagRegistry
{
    /** @var array<string, FeatureFlagDefinition>|null */
    private ?array $definitions = null;

    public function __construct(
        private readonly ?ModuleManifestReader $reader = null,
    ) {}

    /**
     * @return array<string, FeatureFlagDefinition>
     */
    public function all(): array
    {
        return $this->definitions ??= $this->build();
    }

    public function get(string $flag): FeatureFlagDefinition
    {
        $definitions = $this->all();

        if (! array_key_exists($flag, $definitions)) {
            throw UndeclaredFeatureFlagException::forFlag($flag);
        }

        return $definitions[$flag];
    }

    public function has(string $flag): bool
    {
        return array_key_exists($flag, $this->all());
    }

    /**
     * Replace the cached set (tests and composed fixtures).
     *
     * @param  list<FeatureFlagDefinition>  $definitions
     */
    public function replace(array $definitions): void
    {
        $map = [];
        foreach ($definitions as $definition) {
            if (array_key_exists($definition->flag, $map)) {
                throw DuplicateFeatureFlagException::forFlag(
                    $definition->flag,
                    $map[$definition->flag]->module,
                    $definition->module,
                );
            }
            $map[$definition->flag] = $definition;
        }
        ksort($map);
        $this->definitions = $map;
    }

    /**
     * @return array<string, FeatureFlagDefinition>
     */
    private function build(): array
    {
        $reader = $this->reader ?? new ModuleManifestReader(
            array_map(base_path(...), ApplicationTopology::relativeRoots()),
        );

        $map = [];
        foreach ($reader->all() as $manifest) {
            $this->ingestManifest($manifest, $map);
        }
        ksort($map);

        return $map;
    }

    /**
     * @param  array<string, FeatureFlagDefinition>  $map
     */
    private function ingestManifest(ModuleManifest $manifest, array &$map): void
    {
        foreach ($manifest->featureFlags as $flag => $meta) {
            $flag = (string) $flag;
            if (array_key_exists($flag, $map)) {
                throw DuplicateFeatureFlagException::forFlag($flag, $map[$flag]->module, $manifest->module);
            }

            $map[$flag] = new FeatureFlagDefinition(
                flag: $flag,
                default: (bool) ($meta['default'] ?? false),
                module: $manifest->module !== '' ? $manifest->module : $manifest->name,
                description: (string) ($meta['description'] ?? ''),
            );
        }
    }
}
