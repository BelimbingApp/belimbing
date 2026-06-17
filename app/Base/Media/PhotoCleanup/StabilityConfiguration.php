<?php

namespace App\Base\Media\PhotoCleanup;

use App\Base\Media\PhotoCleanup\Contracts\ImageProviderCredentialStore;

class StabilityConfiguration
{
    public const PROVIDER = 'stability';

    public const PROVIDER_LABEL = 'Stability AI';

    public const API_BASE_URL = 'https://api.stability.ai/v2beta/stable-image';

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
}
