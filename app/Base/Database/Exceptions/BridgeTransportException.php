<?php

namespace App\Base\Database\Exceptions;

use RuntimeException;

class BridgeTransportException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }

    public static function invalidReceiveGrant(): self
    {
        return new self(__('The one-time Data Bridge receive key is invalid, unavailable, or expired.'), 401);
    }

    public static function invalidGrantBundle(): self
    {
        return new self(__('The pasted Data Bridge receive key is malformed.'), 422);
    }

    public static function grantConflict(): self
    {
        return new self(__('The one-time Data Bridge receive key was already consumed or became unavailable before this package could be accepted.'), 409);
    }

    public static function invalidUpload(): self
    {
        return new self(__('The Data Bridge receipt body is missing or exceeds the configured package limit.'), 413);
    }

    public static function sendFailed(string $reason): self
    {
        return new self(__('The target refused or could not receive the Data Bridge package: :reason', ['reason' => $reason]), 502);
    }
}
