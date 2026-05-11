<?php
namespace App\Base\Pdf\Exceptions;

use RuntimeException;

class PdfRenderException extends RuntimeException
{
    public static function renderFailed(string $reason): self
    {
        return new self('PDF render failed: '.$reason);
    }

    public static function artifactMissing(string $path): self
    {
        return new self('Expected PDF artifact at '.$path.' but file was not produced.');
    }

    public static function invalidToken(string $reason): self
    {
        return new self('Signed render token invalid: '.$reason);
    }
}
