<?php

namespace App\Base\Database\Exceptions;

use RuntimeException;

class DataShareTransportException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }

    public static function invalidTransferOffer(): self
    {
        return new self(__('The Data Share transfer offer is invalid, revoked, or expired.'), 401);
    }

    public static function invalidOfferBundle(): self
    {
        return new self(__('The pasted Data Share transfer offer is malformed.'), 422);
    }

    public static function invalidUpload(): self
    {
        return new self(__('The Data Share receipt body is missing or exceeds the configured package limit.'), 413);
    }

    public static function fetchFailed(string $reason, int $status = 502): self
    {
        return new self(__('The source offer could not be fetched: :reason', ['reason' => $reason]), $status);
    }

    public static function protectedReceiptStorageUnavailable(): self
    {
        return new self(__('Could not allocate protected Data Share receipt storage.'), 500);
    }
}
