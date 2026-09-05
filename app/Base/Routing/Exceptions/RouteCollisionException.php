<?php

namespace App\Base\Routing\Exceptions;

use App\Base\Foundation\Exceptions\BlbConfigurationException;

/**
 * Two module route files registered the same HTTP method and URI. Laravel
 * keeps the last one silently, so the first module's screen would vanish
 * without an error; the composed application refuses to boot instead.
 */
final class RouteCollisionException extends BlbConfigurationException {}
