<?php

namespace App\Base\Tenancy\Exceptions;

use App\Base\Foundation\Exceptions\BlbException;
use App\Base\Tenancy\Enums\TenancyErrorCode;

/**
 * Thrown when code requires a tenant context but none is resolved.
 *
 * Consumers of tenant context fail closed: no context must surface as an
 * explicit error at the boundary, never as silently unscoped data access.
 */
final class TenantContextMissingException extends BlbException
{
    public function __construct()
    {
        parent::__construct(
            'A tenant context is required but none is resolved for the current execution.',
            TenancyErrorCode::TENANT_CONTEXT_MISSING,
        );
    }
}
