<?php
namespace App\Base\Media\Exceptions;

use RuntimeException;

class MediaStorageException extends RuntimeException
{
    public static function storeFailed(string $disk, string $location): self
    {
        return new self("Failed to write media bytes to disk [{$disk}] at [{$location}].");
    }
}
