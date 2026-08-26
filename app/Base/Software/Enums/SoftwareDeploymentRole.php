<?php

namespace App\Base\Software\Enums;

/**
 * Operator-declared role of this Software/Updates installation.
 *
 * Unlike Data Share's instance role, this value has no APP_ENV fallback:
 * unset means upstream synchronization stays unavailable.
 */
enum SoftwareDeploymentRole: string
{
    case Development = 'development';
    case Staging = 'staging';
    case Production = 'production';

    public function allowsUpstreamSync(): bool
    {
        return $this === self::Development || $this === self::Staging;
    }
}
