<?php

namespace App\Base\Software\Exceptions;

use RuntimeException;

/**
 * Thrown when the deployment maintenance lifecycle cannot proceed — the
 * recovery watchdog could not be armed, maintenance mode could not be entered
 * or left, or the recovery lease was lost mid-update.
 *
 * Extends {@see RuntimeException} so existing callers and catch blocks that
 * handle a generic runtime failure keep working.
 */
class DeploymentMaintenanceException extends RuntimeException {}
