<?php

namespace App\Modules\Commerce\Marketplace\Ebay;

use App\Base\Integration\Services\IntegrationGateway;
use App\Base\Integration\Services\IntegrationRequest;
use App\Modules\Commerce\Marketplace\Exceptions\MarketplaceOperationException;
use App\Modules\Commerce\Marketplace\Models\MarketplaceMetadata;
use Illuminate\Support\Carbon;

class EbayMetadataService
{
    public const KIND_CATEGORY_ASPECTS = 'category_aspects';

    public const KIND_COMPATIBILITY_PROPERTIES = 'compatibility_properties';

    public const KIND_COMPATIBILITY_PROPERTY_VALUES = 'compatibility_property_values';

    public const KIND_AUTOMOTIVE_PARTS_COMPATIBILITY_POLICIES = 'automotive_parts_compatibility_policies';

    public const KIND_ITEM_CONDITION_POLICIES = 'item_condition_policies';

    public function __construct(
        private readonly EbayConfiguration $configuration,
        private readonly EbayApplicationTokenService $applicationTokens,
        private readonly IntegrationGateway $integration,
    ) {}

    public function categoryAspects(int $companyId, string $marketplaceId, string $categoryTreeId, string $categoryId, bool $forceRefresh = false): MarketplaceMetadata
    {
        $path = '/commerce/taxonomy/v1/category_tree/'.$categoryTreeId.'/get_item_aspects_for_category';

        return $this->pullMetadata(
            companyId: $companyId,
            marketplaceId: $marketplaceId,
            kind: self::KIND_CATEGORY_ASPECTS,
            key: $categoryTreeId.':'.$categoryId,
            operation: 'metadata.category_aspects.pull',
            path: $path,
            query: ['category_id' => $categoryId],
            forceRefresh: $forceRefresh,
            metadata: [
                'category_tree_id' => $categoryTreeId,
                'category_id' => $categoryId,
            ],
        );
    }

    public function compatibilityProperties(int $companyId, string $marketplaceId, string $categoryTreeId, string $categoryId, bool $forceRefresh = false): MarketplaceMetadata
    {
        $path = '/commerce/taxonomy/v1/category_tree/'.$categoryTreeId.'/get_compatibility_properties';

        return $this->pullMetadata(
            companyId: $companyId,
            marketplaceId: $marketplaceId,
            kind: self::KIND_COMPATIBILITY_PROPERTIES,
            key: $categoryTreeId.':'.$categoryId,
            operation: 'metadata.compatibility_properties.pull',
            path: $path,
            query: ['category_id' => $categoryId],
            forceRefresh: $forceRefresh,
            metadata: [
                'category_tree_id' => $categoryTreeId,
                'category_id' => $categoryId,
            ],
        );
    }

    /**
     * @param  array<string, string>  $filters
     */
    public function compatibilityPropertyValues(
        int $companyId,
        string $marketplaceId,
        string $categoryTreeId,
        string $categoryId,
        string $compatibilityProperty,
        array $filters = [],
        bool $forceRefresh = false,
    ): MarketplaceMetadata {
        $path = '/commerce/taxonomy/v1/category_tree/'.$categoryTreeId.'/get_compatibility_property_values';
        $query = [
            'category_id' => $categoryId,
            'compatibility_property' => $compatibilityProperty,
        ];

        if ($filters !== []) {
            $query['filter'] = $this->compatibilityFilter($filters);
        }

        return $this->pullMetadata(
            companyId: $companyId,
            marketplaceId: $marketplaceId,
            kind: self::KIND_COMPATIBILITY_PROPERTY_VALUES,
            key: implode(':', [
                $categoryTreeId,
                $categoryId,
                $compatibilityProperty,
                sha1((string) json_encode($filters)),
            ]),
            operation: 'metadata.compatibility_property_values.pull',
            path: $path,
            query: $query,
            forceRefresh: $forceRefresh,
            metadata: [
                'category_tree_id' => $categoryTreeId,
                'category_id' => $categoryId,
                'compatibility_property' => $compatibilityProperty,
                'compatibility_filters' => $filters,
            ],
        );
    }

    /**
     * @param  list<string>  $categoryIds
     */
    public function automotivePartsCompatibilityPolicies(int $companyId, string $marketplaceId, array $categoryIds = [], bool $forceRefresh = false): MarketplaceMetadata
    {
        return $this->pullMetadata(
            companyId: $companyId,
            marketplaceId: $marketplaceId,
            kind: self::KIND_AUTOMOTIVE_PARTS_COMPATIBILITY_POLICIES,
            key: $this->policyKey($categoryIds),
            operation: 'metadata.automotive_parts_compatibility_policies.pull',
            path: '/sell/metadata/v1/marketplace/'.$marketplaceId.'/get_automotive_parts_compatibility_policies',
            query: $this->categoryPolicyQuery($categoryIds),
            forceRefresh: $forceRefresh,
            metadata: ['category_ids' => $categoryIds],
            headers: ['Accept-Encoding' => 'gzip'],
        );
    }

    /**
     * @param  list<string>  $categoryIds
     */
    public function itemConditionPolicies(int $companyId, string $marketplaceId, array $categoryIds = [], bool $forceRefresh = false): MarketplaceMetadata
    {
        return $this->pullMetadata(
            companyId: $companyId,
            marketplaceId: $marketplaceId,
            kind: self::KIND_ITEM_CONDITION_POLICIES,
            key: $this->policyKey($categoryIds),
            operation: 'metadata.item_condition_policies.pull',
            path: '/sell/metadata/v1/marketplace/'.$marketplaceId.'/get_item_condition_policies',
            query: $this->categoryPolicyQuery($categoryIds),
            forceRefresh: $forceRefresh,
            metadata: ['category_ids' => $categoryIds],
            headers: ['Accept-Encoding' => 'gzip'],
        );
    }

    /**
     * @param  array<string, string>  $query
     * @param  array<string, mixed>  $metadata
     * @param  array<string, string>  $headers
     */
    private function pullMetadata(
        int $companyId,
        string $marketplaceId,
        string $kind,
        string $key,
        string $operation,
        string $path,
        array $query,
        bool $forceRefresh,
        array $metadata = [],
        array $headers = [],
    ): MarketplaceMetadata {
        $config = $this->configuration->requireApplicationConfigured($companyId);

        if (! $forceRefresh) {
            $cached = $this->findFresh($config, $marketplaceId, $kind, $key);

            if ($cached instanceof MarketplaceMetadata) {
                return $cached;
            }
        }

        $response = $this->integration->send(new IntegrationRequest(
            system: EbayConfiguration::CHANNEL,
            operation: 'commerce.marketplace.ebay.'.$operation,
            method: 'GET',
            endpoint: rtrim((string) $config['api_base_url'], '/').$path,
            protocolOperation: 'GET '.$path,
            provider: EbayConfiguration::CHANNEL,
            headers: [
                ...$headers,
                'Authorization' => 'Bearer '.$this->applicationTokens->accessToken($companyId),
                'X-EBAY-C-MARKETPLACE-ID' => $marketplaceId,
            ],
            query: $query,
            ownerType: 'company',
            ownerId: $companyId,
            timeoutSeconds: 30,
            retryTimes: 1,
            metadata: [
                ...$metadata,
                'environment' => $config['environment'],
                'marketplace_id' => $marketplaceId,
                'metadata_kind' => $kind,
            ],
        ));

        if ($response->failed()) {
            throw MarketplaceOperationException::requestFailed(
                EbayConfiguration::CHANNEL,
                $operation,
                $response->status,
                $response->exchange?->id,
            );
        }

        $payload = $response->json();
        $payload = is_array($payload) ? $payload : [];

        return MarketplaceMetadata::query()->updateOrCreate(
            [
                'channel' => EbayConfiguration::CHANNEL,
                'environment' => $config['environment'],
                'marketplace_id' => $marketplaceId,
                'kind' => $kind,
                'key' => $key,
            ],
            [
                'payload' => $payload,
                'etag' => $response->headers['ETag'][0] ?? null,
                'fetched_at' => Carbon::now(),
                'expires_at' => Carbon::now()->addDay(),
            ],
        );
    }

    /**
     * @param  list<string>  $categoryIds
     * @return array<string, string>
     */
    private function categoryPolicyQuery(array $categoryIds): array
    {
        if ($categoryIds === []) {
            return [];
        }

        return ['filter' => 'categoryIds:{'.implode('|', $categoryIds).'}'];
    }

    /**
     * @param  list<string>  $categoryIds
     */
    private function policyKey(array $categoryIds): string
    {
        if ($categoryIds === []) {
            return 'all';
        }

        sort($categoryIds);

        return implode('|', $categoryIds);
    }

    /**
     * @param  array<string, string>  $filters
     */
    private function compatibilityFilter(array $filters): string
    {
        return collect($filters)
            ->map(fn (string $value, string $name): string => $name.':'.$value)
            ->implode(',');
    }

    /**
     * @param  array{environment: string}  $config
     */
    private function findFresh(array $config, string $marketplaceId, string $kind, string $key): ?MarketplaceMetadata
    {
        return MarketplaceMetadata::query()
            ->where('channel', EbayConfiguration::CHANNEL)
            ->where('environment', $config['environment'])
            ->where('marketplace_id', $marketplaceId)
            ->where('kind', $kind)
            ->where('key', $key)
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', Carbon::now());
            })
            ->first();
    }
}
