<?php

namespace App\Base\Media\PhotoCleanup\Contracts;

/**
 * Result of a {@see TestsConnection::testConnection()} handshake.
 *
 * Honest, operator-facing: `ok` says whether the stored credentials
 * authenticated; `label` is the short status word; `detail` is an optional
 * one-line context line (e.g. remaining credits); `context` carries structured
 * fields for downstream usage surfacing so the engine/UI does not have to
 * re-call the provider.
 *
 * The factories are the only way to build a result, so every state is named and
 * the contract surface stays honest about what can happen.
 */
final class ConnectionTestResult
{
    /**
     * @param  array<string, mixed>  $context
     */
    private function __construct(
        public readonly bool $ok,
        public readonly string $label,
        public readonly ?string $detail,
        public readonly array $context,
    ) {}

    public static function success(?string $detail = null, array $context = []): self
    {
        return new self(true, (string) __('Connected'), $detail, $context);
    }

    public static function unauthorized(): self
    {
        return new self(
            false,
            (string) __('Unauthorized'),
            (string) __('The stored API key was rejected.'),
            [],
        );
    }

    public static function requestFailed(int $status): self
    {
        return new self(
            false,
            (string) __('Request failed'),
            (string) __('The provider returned HTTP :status.', ['status' => $status]),
            ['status' => $status],
        );
    }

    public static function noKeyStored(): self
    {
        return new self(
            false,
            (string) __('No key stored'),
            (string) __('Add a key before testing the connection.'),
            [],
        );
    }

    public static function noHandshake(string $providerKey): self
    {
        return new self(
            false,
            (string) __('No handshake available'),
            (string) __('A live cleanup run is required to verify :provider.', ['provider' => $providerKey]),
            ['provider' => $providerKey],
        );
    }
}
