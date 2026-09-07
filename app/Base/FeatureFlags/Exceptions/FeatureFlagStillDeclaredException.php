<?php

namespace App\Base\FeatureFlags\Exceptions;

use App\Base\Foundation\Exceptions\BlbConfigurationException;

/**
 * Thrown when purgeOrphanedOverride() is asked to remove a flag that is still
 * declared. Declared overrides clear only through clearOverride().
 */
final class FeatureFlagStillDeclaredException extends BlbConfigurationException
{
    public static function forFlag(string $flag): self
    {
        return new self(sprintf(
            'Feature flag [%s] is still declared; clear it with clearOverride().',
            $flag,
        ));
    }
}
