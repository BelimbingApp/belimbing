<?php

namespace App\Base\AI\Exceptions;

use RuntimeException;

class DocumentExtractionException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
