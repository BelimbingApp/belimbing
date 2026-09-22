<?php

namespace App\Core\AI\Exceptions;

/**
 * Thrown when a chat session path cannot be proven to remain in its authorized root.
 */
final class SessionPathContainmentException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Chat session path escaped its authorized workspace.');
    }
}
