<?php

namespace App\Base\Media\Exceptions;

use RuntimeException;

class MediaAssetUrlException extends RuntimeException
{
    public static function invalidExternalPublicUrl(): self
    {
        return new self('External media asset is missing a valid http(s) public_url.');
    }
}
