<?php

namespace App\Core\AI\Exceptions;

/**
 * Thrown when untrusted input is not a supported chat session identifier.
 */
final class InvalidSessionIdException extends \InvalidArgumentException
{
    public function __construct()
    {
        parent::__construct('Invalid chat session identifier.');
    }
}
