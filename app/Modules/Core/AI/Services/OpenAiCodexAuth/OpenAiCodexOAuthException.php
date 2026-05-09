<?php
namespace App\Modules\Core\AI\Services\OpenAiCodexAuth;

final class OpenAiCodexOAuthException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?string $exchangeId = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
