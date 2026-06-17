<?php

namespace App\Base\Media\PhotoCleanup;

use RuntimeException;

class PhotoCleanupException extends RuntimeException
{
    public static function notConfigured(): self
    {
        return new self('PhotoRoom is not configured. Add an API key under AI Providers → Vision.');
    }

    public static function sourceNotStored(): self
    {
        return new self('Photo cleanup requires a stored image file; this photo has no local file to clean.');
    }

    public static function sourceUnreadable(): self
    {
        return new self('The source photo could not be read from storage.');
    }

    public static function requestFailed(?int $status, ?string $exchangeId): self
    {
        $statusText = $status !== null ? (string) $status : 'no response';
        $exchangeText = $exchangeId !== null ? " See outbound exchange {$exchangeId}." : '';

        return new self("PhotoRoom background removal failed (HTTP {$statusText}).{$exchangeText}");
    }
}
