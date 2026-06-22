<?php

namespace App\Base\Media\PhotoCleanup;

use RuntimeException;

/**
 * Boundary exception for the photo-cleanup subsystem. Provider-agnostic: the
 * active provider is chosen per company ({@see PhotoCleanupSelection}), so a
 * failure names the provider that actually failed rather than hardcoding
 * PhotoRoom. See docs/plans/media-photo-cleanup-providers.md.
 */
class PhotoCleanupException extends RuntimeException
{
    /**
     * No cleanup adapter is wired for the chosen provider, or the chosen
     * provider has no stored key. `providerLabel` names the provider when the
     * choice is valid but unconfigured; the unknown-choice case uses
     * {@see unknownProvider()}.
     */
    public static function notConfigured(?string $providerLabel = null): self
    {
        return new self(
            $providerLabel !== null
                ? $providerLabel.' is not configured for photo cleanup. Add an API key under AI Providers → Vision.'
                : 'Photo cleanup is not configured. Add an API key under AI Providers → Vision and choose a provider.'
        );
    }

    /**
     * The operator's selected provider key has no registered cleanup adapter.
     * Distinct from {@see notConfigured()}: the choice itself is invalid, not
     * the credentials.
     */
    public static function unknownProvider(string $providerKey): self
    {
        return new self(
            'Photo cleanup provider "'.$providerKey.'" is not available. Choose a connected provider on AI Providers → Vision.'
        );
    }

    public static function sourceNotStored(): self
    {
        return new self('Photo cleanup requires a stored image file; this photo has no local file to clean.');
    }

    public static function sourceUnreadable(): self
    {
        return new self('The source photo could not be read from storage.');
    }

    public static function requestFailed(string $providerLabel, ?int $status, ?string $exchangeId): self
    {
        $statusText = $status !== null ? (string) $status : 'no response';
        $exchangeText = $exchangeId !== null ? " See outbound exchange {$exchangeId}." : '';

        return new self("{$providerLabel} background removal failed (HTTP {$statusText}).{$exchangeText}");
    }
}
