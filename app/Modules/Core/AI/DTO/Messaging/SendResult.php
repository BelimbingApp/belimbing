<?php
namespace App\Modules\Core\AI\DTO\Messaging;

/**
 * Result of a send operation on a messaging channel.
 *
 * Encapsulates success/failure state, the platform message ID on success,
 * and an error message on failure.
 */
final readonly class SendResult
{
    /**
     * @param  bool  $success  Whether the send operation succeeded
     * @param  string|null  $messageId  Platform-assigned message ID (on success)
     * @param  string|null  $error  Error description (on failure)
     */
    public function __construct(
        public bool $success,
        public ?string $messageId = null,
        public ?string $error = null,
    ) {}

    /**
     * Create a successful result.
     *
     * @param  string  $messageId  Platform-assigned message ID
     */
    public static function ok(string $messageId): self
    {
        return new self(success: true, messageId: $messageId);
    }

    /**
     * Create a failed result.
     *
     * @param  string  $error  Error description
     */
    public static function fail(string $error): self
    {
        return new self(success: false, error: $error);
    }
}
