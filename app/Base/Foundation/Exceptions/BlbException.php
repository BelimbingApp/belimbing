<?php
namespace App\Base\Foundation\Exceptions;

use App\Base\Foundation\Enums\BlbErrorCode;
use RuntimeException;
use Throwable;

abstract class BlbException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message,
        public readonly BlbErrorCode $reasonCode,
        public readonly array $context = [],
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
