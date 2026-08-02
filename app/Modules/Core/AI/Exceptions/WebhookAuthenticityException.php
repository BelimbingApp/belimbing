<?php

namespace App\Modules\Core\AI\Exceptions;

use App\Modules\Core\AI\Enums\SignalAuthenticityStatus;

/**
 * Thrown when an inbound webhook fails authenticity verification.
 *
 * This is a fail-closed exception: inbound signals must not be parsed,
 * persisted, or routed unless the channel adapter confirms authenticity.
 */
final class WebhookAuthenticityException extends \RuntimeException
{
    public static function noAdapter(string $channel): self
    {
        return new self('Webhook for channel "'.$channel.'" rejected: no adapter registered.');
    }

    public static function notVerified(string $channel, SignalAuthenticityStatus $status): self
    {
        return new self('Webhook for channel "'.$channel.'" rejected: authenticity '.$status->value.'.');
    }
}
