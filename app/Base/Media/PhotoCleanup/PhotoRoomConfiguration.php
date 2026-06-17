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
