<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Exceptions;

final class GithubCopilotAuthException extends \RuntimeException
{
    public function __construct(string $message, public readonly ?string $exchangeId = null)
    {
        parent::__construct($exchangeId === null ? $message : $message.' Integration exchange ['.$exchangeId.'].');
    }

    public static function deviceCodeRequestFailed(int $status, ?string $exchangeId = null): self
    {
        return new self('GitHub device code request failed: HTTP '.$status, $exchangeId);
    }

    public static function missingDeviceCodeFields(): self
    {
        return new self('GitHub device code response missing required fields');
    }

    public static function tokenExchangeFailed(int $status, ?string $exchangeId = null): self
    {
        return new self('Copilot token exchange failed: HTTP '.$status, $exchangeId);
    }

    public static function missingCopilotToken(): self
    {
        return new self('Copilot token response missing token');
    }

    public static function invalidExpiresAt(): self
    {
        return new self('Copilot token response has invalid expires_at');
    }
}
