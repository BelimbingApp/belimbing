<?php

namespace App\Modules\Commerce\Marketplace\Ebay;

use App\Base\Integration\Services\IntegrationGateway;
use App\Base\Integration\Services\IntegrationRequest;
use App\Modules\Commerce\Marketplace\Exceptions\MarketplaceOperationException;
use SimpleXMLElement;

/**
 * Thin client for the legacy eBay Trading API (XML over /ws/api.dll), used to
 * read listings the Inventory API cannot see — listings created in Seller Hub or
 * before the store was connected. The OAuth user token is passed via the
 * X-EBAY-API-IAF-TOKEN header, so no separate Auth'n'Auth token is needed.
 */
class EbayTradingService
{
    private const EBAY_NS = 'urn:ebay:apis:eBLBaseComponents';

    private const COMPATIBILITY_LEVEL = '1267';

    private const ENTRIES_PER_PAGE = 200;

    /** Trading API site IDs keyed by REST marketplace id (default US/0). */
    private const SITE_IDS = [
        'EBAY_US' => '0',
        'EBAY_MOTORS' => '100',
        'EBAY_CA' => '2',
        'EBAY_GB' => '3',
        'EBAY_AU' => '15',
        'EBAY_DE' => '77',
    ];

    public function __construct(
        private readonly EbayConfiguration $configuration,
        private readonly EbayOAuthService $oauth,
        private readonly IntegrationGateway $integration,
    ) {}

    /**
     * Fetch the seller's active listings via GetMyeBaySelling, paginated up to
     * $maxPages (200 per page). `complete` is true only when every page was read —
     * reconciliation must not end "missing" listings off a partial set.
     *
     * @return array{listings: list<array{item_id: string, title: string, sku: string|null, price_amount: int|null, currency_code: string|null, quantity: int|null, listing_type: string|null, view_url: string|null}>, complete: bool}
     */
    public function fetchActiveListings(int $companyId, int $maxPages = 10): array
    {
        $config = $this->configuration->forCompany($companyId);
        $accessToken = $this->oauth->accessToken($companyId);
        $siteId = self::SITE_IDS[(string) ($config['marketplace_id'] ?? '')] ?? '0';

        $listings = [];
        $page = 1;
        $totalPages = 1;

        do {
            $xml = $this->call($companyId, $config, $accessToken, $siteId, 'GetMyeBaySelling', $this->activeListRequest($page));

            foreach ($xml->xpath('//e:ActiveList/e:ItemArray/e:Item') ?: [] as $item) {
                $summary = $this->summarizeItem($item);

                if ($summary !== null) {
                    $listings[$summary['item_id']] = $summary;
                }
            }

            $totalPages = (int) $this->value($xml, '//e:ActiveList/e:PaginationResult/e:TotalNumberOfPages');
            $page++;
        } while ($page <= $totalPages && $page <= $maxPages);

        return [
            'listings' => array_values($listings),
            'complete' => $totalPages <= $maxPages,
        ];
    }

    private function activeListRequest(int $page): string
    {
        return <<<XML
        <?xml version="1.0" encoding="utf-8"?>
        <GetMyeBaySellingRequest xmlns="urn:ebay:apis:eBLBaseComponents">
          <ErrorLanguage>en_US</ErrorLanguage>
          <WarningLevel>High</WarningLevel>
          <ActiveList>
            <Include>true</Include>
            <Pagination>
              <EntriesPerPage>{$this->entriesPerPage()}</EntriesPerPage>
              <PageNumber>{$page}</PageNumber>
            </Pagination>
          </ActiveList>
        </GetMyeBaySellingRequest>
        XML;
    }

    private function entriesPerPage(): int
    {
        return self::ENTRIES_PER_PAGE;
    }

    /**
     * @return array{item_id: string, title: string, sku: string|null, price_amount: int|null, currency_code: string|null, quantity: int|null, listing_type: string|null, view_url: string|null}|null
     */
    private function summarizeItem(SimpleXMLElement $item): ?array
    {
        $item->registerXPathNamespace('e', self::EBAY_NS);
        $itemId = trim($this->value($item, 'e:ItemID'));

        if ($itemId === '') {
            return null;
        }

        $priceNodes = $item->xpath('e:SellingStatus/e:CurrentPrice') ?: [];
        $priceNode = $priceNodes[0] ?? null;
        $priceValue = $priceNode !== null ? (float) (string) $priceNode : null;
        $currency = $priceNode !== null ? (string) ($priceNode['currencyID'] ?? '') : '';

        $quantity = $this->value($item, 'e:Quantity');
        $sold = $this->value($item, 'e:SellingStatus/e:QuantitySold');
        $available = $quantity !== '' ? max(0, (int) $quantity - (int) $sold) : null;

        $sku = trim($this->value($item, 'e:SKU'));
        $viewUrl = trim($this->value($item, 'e:ListingDetails/e:ViewItemURL'));
        $listingType = trim($this->value($item, 'e:ListingType'));

        return [
            'item_id' => $itemId,
            'title' => trim($this->value($item, 'e:Title')),
            'sku' => $sku !== '' ? $sku : null,
            'price_amount' => $priceValue !== null ? (int) round($priceValue * 100) : null,
            'currency_code' => $currency !== '' ? strtoupper($currency) : null,
            'quantity' => $available,
            'listing_type' => $listingType !== '' ? $listingType : null,
            'view_url' => $viewUrl !== '' ? $viewUrl : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function call(int $companyId, array $config, string $accessToken, string $siteId, string $callName, string $body): SimpleXMLElement
    {
        $response = $this->integration->send(new IntegrationRequest(
            system: EbayConfiguration::CHANNEL,
            operation: 'commerce.marketplace.ebay.trading.'.strtolower($callName),
            method: 'POST',
            endpoint: rtrim((string) $config['api_base_url'], '/').'/ws/api.dll',
            protocol: 'xml',
            protocolOperation: $callName,
            provider: EbayConfiguration::CHANNEL,
            headers: [
                'X-EBAY-API-CALL-NAME' => $callName,
                'X-EBAY-API-SITEID' => $siteId,
                'X-EBAY-API-COMPATIBILITY-LEVEL' => self::COMPATIBILITY_LEVEL,
                'X-EBAY-API-IAF-TOKEN' => $accessToken,
                'Content-Type' => 'application/xml',
            ],
            body: $body,
            ownerType: 'company',
            ownerId: $companyId,
            timeoutSeconds: 45,
            retryTimes: 1,
            asJson: false,
            metadata: ['marketplace_id' => $config['marketplace_id'] ?? null],
        ));

        if ($response->failed()) {
            throw MarketplaceOperationException::requestFailed(
                EbayConfiguration::CHANNEL,
                'trading.'.strtolower($callName),
                $response->status,
                $response->exchange?->id,
            );
        }

        $xml = $this->parse($response->body);

        $ack = $this->value($xml, '//e:Ack');

        if (strtolower($ack) === 'failure') {
            throw new MarketplaceOperationException($this->errorMessage($xml));
        }

        return $xml;
    }

    private function parse(string $body): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_use_internal_errors($previous);

        if (! $xml instanceof SimpleXMLElement) {
            throw new MarketplaceOperationException('eBay returned an unreadable Trading API response.');
        }

        $xml->registerXPathNamespace('e', self::EBAY_NS);

        return $xml;
    }

    private function errorMessage(SimpleXMLElement $xml): string
    {
        $message = $this->value($xml, '//e:Errors/e:LongMessage');

        if (trim($message) === '') {
            $message = $this->value($xml, '//e:Errors/e:ShortMessage');
        }

        return trim($message) !== ''
            ? trim($message)
            : (string) __('eBay rejected the Trading API request.');
    }

    private function value(SimpleXMLElement $node, string $xpath): string
    {
        $found = $node->xpath($xpath) ?: [];

        return $found === [] ? '' : (string) $found[0];
    }
}
