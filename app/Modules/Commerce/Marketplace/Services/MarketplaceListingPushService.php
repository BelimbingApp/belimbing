<?php

namespace App\Modules\Commerce\Marketplace\Services;

use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Commerce\Marketplace\Exceptions\MarketplaceOperationException;
use App\Modules\Commerce\Marketplace\Models\Listing;
use Throwable;

class MarketplaceListingPushService
{
    public function __construct(
        private readonly MarketplaceChannelRegistry $channels,
    ) {}

    /**
     * @param  list<string>  $channelKeys
     * @return array{results: list<array{channel: string, label: string, operation: string, payload: array<string, mixed>}>, failures: list<array{channel: string, label: string, message: string}>}
     */
    public function push(Item $item, array $channelKeys): array
    {
        $results = [];
        $failures = [];

        foreach ($this->normalizeChannelKeys($channelKeys) as $channelKey) {
            $descriptor = $this->channels->descriptor($channelKey);
            $channel = $this->channels->channel($channelKey);

            try {
                $listing = $this->writableListing($item, $channelKey);

                if ($listing instanceof Listing && $descriptor->supports('revise_listing')) {
                    $operation = 'revise';
                    $payload = $channel->reviseListing($listing);
                } elseif ($descriptor->supports('create_listing')) {
                    $operation = 'create';
                    $payload = $channel->createListing($item->fresh() ?? $item);
                } else {
                    throw MarketplaceOperationException::writePathNotEnabled($channelKey);
                }

                $results[] = [
                    'channel' => $channelKey,
                    'label' => $descriptor->label,
                    'operation' => $operation,
                    'payload' => $payload,
                ];
            } catch (Throwable $exception) {
                $failures[] = [
                    'channel' => $channelKey,
                    'label' => $descriptor->label,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return [
            'results' => $results,
            'failures' => $failures,
        ];
    }

    private function writableListing(Item $item, string $channelKey): ?Listing
    {
        return Listing::query()
            ->where('company_id', $item->company_id)
            ->where('item_id', $item->id)
            ->where('channel', $channelKey)
            ->whereNull('ended_at')
            ->latest('updated_at')
            ->first();
    }

    /**
     * @param  list<string>  $channelKeys
     * @return list<string>
     */
    private function normalizeChannelKeys(array $channelKeys): array
    {
        return collect($channelKeys)
            ->map(fn (mixed $channel): string => trim((string) $channel))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
