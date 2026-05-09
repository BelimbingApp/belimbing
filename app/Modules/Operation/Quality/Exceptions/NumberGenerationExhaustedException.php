<?php
namespace App\Modules\Operation\Quality\Exceptions;

use App\Base\Foundation\Exceptions\BlbInvariantViolationException;

/**
 * Thrown when repeated retries still cannot allocate a unique quality number.
 */
final class NumberGenerationExhaustedException extends BlbInvariantViolationException
{
    public function __construct(string $recordType)
    {
        parent::__construct("Failed to generate a unique {$recordType} number.");
    }
}
