<?php

namespace App\Base\Foundation\Exceptions;

use App\Base\Foundation\Enums\BlbErrorCode;
use App\Base\Foundation\Enums\FoundationErrorCode;
use Throwable;

class BlbIntegrationException extends BlbException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message,
        BlbErrorCode $reasonCode = FoundationErrorCode::BLB_INTEGRATION,
        array $context = [],
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $reasonCode, $context, $code, $previous);
    }
}
