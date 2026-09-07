<?php

namespace App\Base\Authz\Exceptions;

/**
 * Impersonation was refused by ImpersonationManager (#875).
 *
 * Nested starts, cross-tenant targets, and self-impersonation are defined out
 * of existence at the service — callers must not treat these as success.
 */
final class ImpersonationRefusedException extends \RuntimeException {}
