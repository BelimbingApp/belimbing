<?php

namespace App\Base\Tenancy\Exceptions;

use App\Base\Foundation\Exceptions\BlbInvariantViolationException;

/**
 * The declared Base/Core queued-job inventory no longer matches discovery.
 */
final class PlatformAsyncEntryPointInventoryException extends BlbInvariantViolationException {}
