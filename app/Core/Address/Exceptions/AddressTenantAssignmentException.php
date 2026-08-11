<?php

namespace App\Core\Address\Exceptions;

use App\Base\Foundation\Exceptions\BlbInvariantViolationException;

final class AddressTenantAssignmentException extends BlbInvariantViolationException
{
    /** @param array<string, mixed> $context */
    public function __construct(string $message, array $context = [])
    {
        parent::__construct($message, context: $context);
    }
}
