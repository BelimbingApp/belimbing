<?php

namespace App\Base\FeatureFlags\Services;

/**
 * One declared feature flag from a module's `extra.blb.feature-flags` block.
 */
final readonly class FeatureFlagDefinition
{
    public function __construct(
        public string $flag,
        public bool $default,
        public string $module,
        public string $description = '',
    ) {}
}
