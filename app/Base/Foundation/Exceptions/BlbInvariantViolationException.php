<?php
namespace App\Base\Foundation\Exceptions;

use App\Base\Foundation\Enums\BlbErrorCode;
use Throwable;

class BlbInvariantViolationException extends BlbException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message,
        BlbErrorCode $reasonCode = BlbErrorCode::BLB_INVARIANT_VIOLATION,
        array $context = [],
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $reasonCode, $context, $code, $previous);
    }
}
