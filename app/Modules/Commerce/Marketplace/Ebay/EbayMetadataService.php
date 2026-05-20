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

    public function __construct(
        private readonly EbayConfiguration $configuration,
        private readonly EbayApplicationTokenService $applicationTokens,
        private readonly IntegrationGateway $integration,
    ) {}

    public function categoryAspects(int $companyId, string $marketplaceId, string $categoryTreeId, string $categoryId, bool $forceRefresh = false): MarketplaceMetadata
    {
        $config = $this->configuration->requireApplicationConfigured($companyId);
        $key = $categoryTreeId.':'.$categoryId;

        if (! $forceRefresh) {
            $cached = $this->findFresh($config, $marketplaceId, self::KIND_CATEGORY_ASPECTS, $key);

            if ($cached instanceof MarketplaceMetadata) {
                return $cached;
            }
        }

        $path = '/commerce/taxonomy/v1/category_tree/'.$categoryTreeId.'/get_item_aspects_for_category';
        $response = $this->integration->send(new IntegrationRequest(
            system: EbayConfiguration::CHANNEL,
            operation: 'commerce.marketplace.ebay.metadata.category_aspects.pull',
            method: 'GET',
            endpoint: rtrim((string) $config['api_base_url'], '/').$path,
            protocolOperation: 'GET '.$path,
            provider: EbayConfiguration::CHANNEL,
            headers: [
                'Authorization' => 'Bearer '.$this->applicationTokens->accessToken($companyId),
                'X-EBAY-C-MARKETPLACE-ID' => $marketplaceId,
            ],
            query: ['category_id' => $categoryId],
            ownerType: 'company',
            ownerId: $companyId,
            timeoutSeconds: 30,
            retryTimes: 1,
            metadata: [
                'environment' => $config['environment'],
                'marketplace_id' => $marketplaceId,
                'category_tree_id' => $categoryTreeId,
                'category_id' => $categoryId,
                'metadata_kind' => self::KIND_CATEGORY_ASPECTS,
            ],
        ));

        if ($response->failed()) {
            throw MarketplaceOperationException::requestFailed(
                EbayConfiguration::CHANNEL,
                'metadata.category_aspects.pull',
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
                'kind' => self::KIND_CATEGORY_ASPECTS,
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
