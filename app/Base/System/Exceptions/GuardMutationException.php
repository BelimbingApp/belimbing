<?php

namespace App\Base\System\Exceptions;

use RuntimeException;

/**
 * Raised when a guard-line mutation cannot be applied safely.
 */
final class GuardMutationException extends RuntimeException {}
