<?php

namespace App\Base\Media\PhotoCleanup;

use App\Base\Media\PhotoCleanup\Contracts\ImageProviderCredentialStore;

class BedrockConfiguration
{
    public const PROVIDER = 'bedrock';

    public const PROVIDER_LABEL = 'AWS Bedrock';

    public const REGION_US_EAST_1 = 'us-east-1';

    public const REGION_US_EAST_2 = 'us-east-2';

    public const REGION_US_WEST_2 = 'us-west-2';

    public const DEFAULT_REGION = self::REGION_US_EAST_1;

    public const ALLOWED_REGIONS = [self::REGION_US_EAST_1, self::REGION_US_EAST_2, self::REGION_US_WEST_2];

    public const REMOVE_BACKGROUND_MODEL = 'stability.stable-image-remove-background-v1:0';

    public function __construct(
        private readonly ImageProviderCredentialStore $credentials,
    ) {}

    public static function endpointFor(string $region): string
    {
        return "https://bedrock-runtime.{$region}.amazonaws.com";
    }

    /**
     * @return array{api_key: string|null, region: string, api_base_url: string}
     */
    public function resolve(?int $companyId = null): array
    {
        $region = $this->credentials->connectionConfig($companyId, self::PROVIDER)['region'] ?? self::DEFAULT_REGION;
        $region = in_array($region, self::ALLOWED_REGIONS, true) ? $region : self::DEFAULT_REGION;

        return [
            'api_key' => $this->credentials->apiKey($companyId, self::PROVIDER),
            'region' => $region,
            'api_base_url' => self::endpointFor($region),
        ];
    }
}
