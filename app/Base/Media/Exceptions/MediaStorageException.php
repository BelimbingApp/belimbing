<?php

namespace App\Base\Media\Exceptions;

use RuntimeException;

class MediaStorageException extends RuntimeException
{
    public static function storeFailed(string $disk, string $location): self
    {
        return new self("Failed to write media bytes to disk [{$disk}] at [{$location}].");
    }

    public static function invalidExternalUrl(): self
    {
        return new self('External media asset is missing a valid http(s) public_url.');
    }

    public static function disallowedType(string $mimeType): self
    {
        return new self("Media type [{$mimeType}] is not allowed for upload.");
    }
}
