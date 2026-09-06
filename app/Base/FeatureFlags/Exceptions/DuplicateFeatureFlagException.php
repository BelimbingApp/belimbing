<?php

namespace App\Base\FeatureFlags\Exceptions;

use App\Base\Foundation\Exceptions\BlbConfigurationException;

/**
 * Thrown when two module descriptors claim the same feature-flag identity.
 */
final class DuplicateFeatureFlagException extends BlbConfigurationException
{
    public static function forFlag(string $flag, string $firstModule, string $secondModule): self
    {
        return new self(sprintf(
            'Feature flag [%s] is declared by both [%s] and [%s].',
            $flag,
            $firstModule,
            $secondModule,
        ));
    }
}
