<?php

namespace App\Base\Software\Exceptions;

use RuntimeException;

/**
 * Thrown when an upstream-synchronization action is refused by the
 * deployment-role / authorization gate. Lane 3 actions must fail here
 * rather than at a hidden button.
 */
class UpstreamSyncUnavailableException extends RuntimeException {}
