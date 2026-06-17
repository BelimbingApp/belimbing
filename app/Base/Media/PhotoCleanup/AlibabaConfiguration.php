<?php

namespace App\Base\Media\PhotoCleanup;

use App\Base\Media\PhotoCleanup\Contracts\ImageProviderCredentialStore;
use App\Modules\Core\AI\Models\AiProvider;

/**
 * Resolves the Alibaba Cloud Model Studio (DashScope) credentials for image
 * editing. Credentials live in company-scoped {@see AiProvider}
 * rows (family {@code image}).
 */
class AlibabaConfiguration
{
    public const PROVIDER = 'alibaba';

    public const PROVIDER_LABEL = 'Alibaba Model Studio';

    public const REGION_INTERNATIONAL = 'international';

    public const REGION_CHINA = 'china';

    public const ENDPOINT_INTERNATIONAL = 'https://dashscope-intl.aliyuncs.com';

    public const ENDPOINT_CHINA = 'https://dashscope.aliyuncs.com';

    public function __construct(
        private readonly ImageProviderCredentialStore $credentials,
    ) {}

    /**
     * @return array{api_key: string|null, region: string, api_base_url: string}
     */
    public function resolve(?int $companyId = null): array
    {
        $region = $this->credentials->connectionConfig($companyId, self::PROVIDER)['region'] ?? self::REGION_INTERNATIONAL;
        $region = $region === self::REGION_CHINA ? self::REGION_CHINA : self::REGION_INTERNATIONAL;

        return [
            'api_key' => $this->credentials->apiKey($companyId, self::PROVIDER),
            'region' => $region,
            'api_base_url' => $region === self::REGION_CHINA ? self::ENDPOINT_CHINA : self::ENDPOINT_INTERNATIONAL,
        ];
    }
}
