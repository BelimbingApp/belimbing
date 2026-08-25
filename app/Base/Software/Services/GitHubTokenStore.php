<?php

namespace App\Base\Software\Services;

use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\Models\Setting;

/**
 * Per-owner GitHub personal access tokens, keyed by lowercase owner login.
 */
final class GitHubTokenStore
{
    private const TOKEN_PREFIX = 'integrations.github.token.';

    public function __construct(private readonly SettingsService $settings) {}

    /**
     * Owners with a non-empty stored token (lowercase logins).
     *
     * @return list<string>
     */
    public function owners(): array
    {
        return Setting::query()
            ->whereNull('scope_type')
            ->whereNull('scope_id')
            ->where('key', 'like', self::TOKEN_PREFIX.'%')
            ->pluck('key')
            ->map(fn (string $key): string => substr($key, strlen(self::TOKEN_PREFIX)))
            ->filter(fn (string $owner): bool => $owner !== '' && $this->tokenFor($owner) !== null)
            ->values()
            ->all();
    }

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
