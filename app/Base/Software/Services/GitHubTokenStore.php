<?php

namespace App\Base\Software\Services;

use App\Base\Settings\Contracts\SettingsService;

/**
 * Per-owner GitHub personal access tokens, keyed by lowercase owner login.
 */
final class GitHubTokenStore
{
    private const TOKEN_PREFIX = 'integrations.github.token.';

    public function __construct(private readonly SettingsService $settings) {}

    public function tokenFor(string $owner): ?string
    {
        $token = $this->settings->get(self::TOKEN_PREFIX.strtolower($owner));

        return is_string($token) && trim($token) !== '' ? trim($token) : null;
    }

    public function saveToken(string $owner, string $token): void
    {
        $this->settings->set(self::TOKEN_PREFIX.strtolower($owner), trim($token));
    }
}
