<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Media\Exceptions;

use RuntimeException;

class MediaStorageException extends RuntimeException
{
    public static function storeFailed(string $disk, string $location): self
    {
        return new self("Failed to write media bytes to disk [{$disk}] at [{$location}].");
    }
}
