<?php

namespace App\Modules\Commerce\Marketplace\Services;

use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Commerce\Marketplace\Models\Listing;
use Throwable;

/**
 * Keeps every channel listing in step with the inventory's available quantity.
 *
 * Inventory is the single source of truth; each channel listing's available
 * quantity is a projection of it. When an item's quantity changes (a manual
 * edit, or a pulled sale), this reconciles the item's active listings across
 * every channel:
 *
 *  - available 0  → end the listing on each channel (a one-off that sold on one
 *                   channel must not stay sellable on another — the overselling
 *                   guard).
 *  - available > 0 → revise the listing so the channel reflects the new quantity.
 *
 * Only Belimbing-managed, in-sync listings are written. Imported or drifted
 * listings are reported, never clobbered — the operator resolves those. This is
 * the automatic safety mechanism; deliberate content publishing stays gated
 * behind the per-item push actions.
 */
class MarketplaceAvailabilitySyncService
{
    public function __construct(
        private readonly MarketplaceChannelRegistry $channels,
    ) {}

    /**
     * @return array{
     *     available: int,
     *     ended: list<array{channel: string, label: string}>,
     *     revised: list<array{channel: string, label: string}>,
     *     skipped: list<array{channel: string, label: string, reason: string}>,
     *     failures: list<array{channel: string, label: string, message: string}>
     * }
     */
    public function syncItem(Item $item): array
    {
        $available = max(0, (int) $item->quantity_on_hand);
        $descriptors = $this->channels->all();

        $ended = [];
        $revised = [];
        $skipped = [];
        $failures = [];

        $listings = Listing::query()
            ->where('company_id', $item->company_id)
            ->where('item_id', $item->id)
            ->whereNull('ended_at')
            ->get();

        foreach ($listings as $listing) {
            $channelKey = $listing->channel;
            $descriptor = $descriptors[$channelKey] ?? null;
            $label = $descriptor->label ?? $channelKey;

            if ($descriptor === null) {
                $skipped[] = ['channel' => $channelKey, 'label' => $label, 'reason' => __('Channel is not registered.')];

                continue;
            }

            // Never overwrite a listing the operator has not adopted, or one that
            // changed on the channel — surface it instead so it can be resolved.
            if (! $listing->isBelimbingManaged()) {
                $skipped[] = ['channel' => $channelKey, 'label' => $label, 'reason' => __('Not Belimbing-managed — link or adopt it first (may oversell).')];

                continue;
            }

            if ($listing->drift_status === Listing::DRIFT_DRIFTED) {
                $skipped[] = ['channel' => $channelKey, 'label' => $label, 'reason' => __('Changed on the channel — resolve drift before syncing.')];

                continue;
            }

            $operation = $available === 0 ? 'end_listing' : 'revise_listing';

            if (! $descriptor->supports($operation)) {
                $skipped[] = ['channel' => $channelKey, 'label' => $label, 'reason' => __('Channel does not support this update.')];

                continue;
            }

            try {
                $channel = $this->channels->channel($channelKey);

                if ($available === 0) {
                    $channel->endListing($listing);
                    $ended[] = ['channel' => $channelKey, 'label' => $label];
                } else {
                    $channel->reviseListing($listing);
                    $revised[] = ['channel' => $channelKey, 'label' => $label];
                }
            } catch (Throwable $exception) {
                $failures[] = ['channel' => $channelKey, 'label' => $label, 'message' => $exception->getMessage()];
            }
        }

        return [
            'available' => $available,
            'ended' => $ended,
            'revised' => $revised,
            'skipped' => $skipped,
            'failures' => $failures,
        ];
    }
}
