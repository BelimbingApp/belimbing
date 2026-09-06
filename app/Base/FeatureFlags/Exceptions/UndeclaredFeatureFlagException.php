<?php

namespace App\Base\FeatureFlags\Exceptions;

use App\Base\Foundation\Exceptions\BlbConfigurationException;

/**
 * Thrown when a caller asks for a feature flag that no module descriptor
 * declared under `extra.blb.feature-flags`.
 */
final class UndeclaredFeatureFlagException extends BlbConfigurationException
{
    public static function forFlag(string $flag): self
    {
        return new self(sprintf('Feature flag [%s] is not declared in any module descriptor.', $flag));
    }
}
