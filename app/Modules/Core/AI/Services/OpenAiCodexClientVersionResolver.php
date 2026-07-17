<?php

namespace App\Modules\Core\AI\Services;

use App\Base\Integration\Services\IntegrationGateway;
use App\Base\Integration\Services\IntegrationRequest;
use Illuminate\Support\Facades\Cache;

/**
 * Resolves the latest stable Codex CLI version from GitHub releases.
 *
 * The ChatGPT Codex backend filters `GET /codex/models` by the claimed
 * `client_version`, so a stale pinned version silently hides newly released
 * models (HTTP 200, shorter list). Following the latest stable release keeps
 * model discovery current without operator intervention.
 *
 * Results are cached for a day as a plain string (cache stores scalars only).
 * Failures are not cached so the next sync retries.
 */
class OpenAiCodexClientVersionResolver
{
    private const LATEST_RELEASE_URL = 'https://api.github.com/repos/openai/codex/releases/latest';

    private const CACHE_KEY = 'ai:openai-codex:latest-client-version';

    private const CACHE_TTL_SECONDS = 86400;

    public function __construct(
        private readonly ?IntegrationGateway $gateway = null,
    ) {}

    /**
     * Latest stable Codex CLI version (e.g. "0.144.5"), or null when
     * disabled by config or the release lookup fails.
     */
    public function latest(): ?string
    {
        if (! (bool) config('ai.openai_codex.auto_client_version', true)) {
            return null;
        }

        $cached = Cache::get(self::CACHE_KEY);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $version = $this->fetchLatest();

        if ($version !== null) {
            Cache::put(self::CACHE_KEY, $version, self::CACHE_TTL_SECONDS);
        }

        return $version;
    }

    private function fetchLatest(): ?string
    {
        try {
            $response = $this->integrationGateway()->send(new IntegrationRequest(
                system: 'ai_provider',
                operation: 'ai.provider.codex.client_version.resolve',
                method: 'GET',
                endpoint: self::LATEST_RELEASE_URL,
                protocolOperation: 'GET /repos/openai/codex/releases/latest',
                provider: 'api.github.com',
                headers: ['Accept' => 'application/vnd.github+json'],
                timeoutSeconds: 10,
            ));
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $decoded = $response->json();

        return $this->versionFromTag(is_array($decoded) ? ($decoded['tag_name'] ?? null) : null);
    }

    /**
     * GitHub tags Codex CLI releases as "rust-vX.Y.Z"; /releases/latest never
     * returns prereleases or drafts, so a well-formed tag is a stable version.
     */
    private function versionFromTag(mixed $tag): ?string
    {
        if (! is_string($tag) || preg_match('/^rust-v(\d+\.\d+\.\d+)$/', $tag, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function integrationGateway(): IntegrationGateway
    {
        return $this->gateway ?? app(IntegrationGateway::class);
    }
}
