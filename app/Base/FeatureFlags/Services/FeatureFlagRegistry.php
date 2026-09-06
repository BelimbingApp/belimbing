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

    /** @var array<string, list<FeatureFlagDefinition>>|null */
    private ?array $declarations = null;

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

    /**
     * Group every manifest declaration by flag for read-only diagnostics.
     * Runtime resolution remains strict through all(), get(), and has().
     *
     * @return array<string, list<FeatureFlagDefinition>>
     */
    public function declarations(): array
    {
        return $this->declarations ??= $this->buildDeclarations();
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
        $this->declarations = array_map(fn (FeatureFlagDefinition $definition): array => [$definition], $map);
    }

    /**
     * @return array<string, FeatureFlagDefinition>
     */
    private function build(): array
    {
        $map = [];
        foreach ($this->declarations() as $flag => $declarations) {
            if (count($declarations) > 1) {
                throw DuplicateFeatureFlagException::forFlag(
                    $flag,
                    $declarations[0]->module,
                    $declarations[1]->module,
                );
            }
            $map[$flag] = $declarations[0];
        }

        return $map;
    }

    /**
     * @return array<string, list<FeatureFlagDefinition>>
     */
    private function buildDeclarations(): array
    {
        $reader = $this->reader ?? new ModuleManifestReader(
            array_map(base_path(...), ApplicationTopology::relativeRoots()),
        );
        $grouped = [];

        foreach ($reader->all() as $manifest) {
            $this->ingestManifest($manifest, $grouped);
        }
        ksort($grouped);

        return $grouped;
    }

    /**
     * @param  array<string, list<FeatureFlagDefinition>>  $grouped
     */
    private function ingestManifest(ModuleManifest $manifest, array &$grouped): void
    {
        foreach ($manifest->featureFlags as $flag => $meta) {
            $flag = (string) $flag;
            $grouped[$flag][] = new FeatureFlagDefinition(
                flag: $flag,
                default: (bool) ($meta['default'] ?? false),
                module: $manifest->module !== '' ? $manifest->module : $manifest->name,
                description: (string) ($meta['description'] ?? ''),
            );
        }
    }
}
