<?php
namespace App\Modules\Core\AI\Exceptions;

/**
 * Thrown when a requested messaging channel has no registered adapter.
 */
final class ChannelNotAvailableException extends \RuntimeException
{
    public function __construct(string $channel)
    {
        parent::__construct('Channel "'.$channel.'" is not available. No adapter registered.');
    }
}
