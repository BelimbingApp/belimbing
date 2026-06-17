<?php

namespace App\Base\Media\PhotoCleanup;

use App\Base\Media\PhotoCleanup\Contracts\ImageProviderCredentialStore;

class PoofConfiguration
{
    public const PROVIDER = 'poof';

    public const PROVIDER_LABEL = 'Poof';

    public const API_BASE_URL = 'https://api.poof.bg/v1';

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
