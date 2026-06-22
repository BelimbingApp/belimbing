<?php

namespace App\Base\Media\PhotoCleanup;

use App\Base\Media\PhotoCleanup\Contracts\ImageProviderCredentialStore;
use App\Modules\Core\AI\Models\AiProvider;

/**
 * Resolves the PhotoRoom API key for the Remove Background API. Credentials
 * live in company-scoped {@see AiProvider} rows
 * (family {@code image}), managed through the Vision tab on AI Providers.
 */
class PhotoRoomConfiguration
{
    public const PROVIDER = 'photoroom';

    public const PROVIDER_LABEL = 'PhotoRoom';

    public const API_BASE_URL = 'https://sdk.photoroom.com';

    /**
     * Account/usage host for the connectivity handshake. PhotoRoom exposes
     * account details on a separate host from the remove-background API; the
     * stored `x-api-key` works against both. See
     * docs/plans/media-photo-cleanup-providers.md.
     */
    public const ACCOUNT_API_BASE_URL = 'https://image-api.photoroom.com';

    public const ACCOUNT_ENDPOINT = self::ACCOUNT_API_BASE_URL.'/v2/account';

    /**
     * Legacy account/usage endpoint. PhotoRoom keeps both `/v1/account`
     * (returns `credits`) and `/v2/account` (returns `images` + `plan`) live;
     * an account on the older pricing version rejects `/v2/account` with a
     * 400, so the handshake falls back here. See
     * docs/plans/media-photo-cleanup-providers.md.
     */
    public const ACCOUNT_ENDPOINT_V1 = self::ACCOUNT_API_BASE_URL.'/v1/account';

    /**
     * PhotoRoom sandbox keys are prefixed `sandbox_` (per the sandbox-mode
     * docs); the prefix is the documented, definitive marker of a sandbox
     * key. The handshake branches on it because sandbox accounts expose no
     * account/quota state — both account endpoints 400 — so a sandbox key is
     * verified by a minimal probe edit instead of an account read.
     */
    public const SANDBOX_KEY_PREFIX = 'sandbox_';

    public static function isSandboxKey(?string $apiKey): bool
    {
        return $apiKey !== null && str_starts_with($apiKey, self::SANDBOX_KEY_PREFIX);
    }

    public function __construct(
        private readonly ImageProviderCredentialStore $credentials,
    ) {}

    /**
     * @return array{api_key: string|null, api_base_url: string}
     */
    public function resolve(?int $companyId = null): array
    {
        return [
            'api_key' => $this->credentials->apiKey($companyId, self::PROVIDER),
            'api_base_url' => self::API_BASE_URL,
        ];
    }

    /**
     * @return array{api_key: string, api_base_url: string}
     */
    public function requireConfigured(?int $companyId = null): array
    {
        $config = $this->resolve($companyId);

        if ($config['api_key'] === null) {
            throw PhotoCleanupException::notConfigured();
        }

        /** @var array{api_key: string, api_base_url: string} $config */
        return $config;
    }
}
