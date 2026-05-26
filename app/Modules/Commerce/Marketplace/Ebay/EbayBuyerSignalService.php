<?php

namespace App\Modules\Commerce\Marketplace\Ebay;

use App\Modules\Commerce\Marketplace\Models\Listing;
use App\Modules\Commerce\Sales\Models\Order;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class EbayBuyerSignalService
{
    /**
     * @param  Collection<int, Listing>  $listings
     * @return Collection<int, array<string, mixed>>
     */
    public function trustSignals(int $companyId, Collection $listings): Collection
    {
        $listingById = $listings->keyBy('id');
        $listingIdByExternalId = $listings
            ->filter(fn (Listing $listing): bool => $listing->external_listing_id !== null)
            ->mapWithKeys(fn (Listing $listing): array => [$listing->external_listing_id => $listing->id]);

        return Order::query()
            ->where('company_id', $companyId)
            ->where('channel', EbayConfiguration::CHANNEL)
            ->with('lines')
            ->get()
            ->flatMap(fn (Order $order): array => $this->trustSignalsForOrder($order, $listingById, $listingIdByExternalId))
            ->values();
    }

    /**
     * @param  Collection<int, Listing>  $listingById
     * @param  Collection<string, int>  $listingIdByExternalId
     * @return list<array<string, mixed>>
     */
    private function trustSignalsForOrder(Order $order, Collection $listingById, Collection $listingIdByExternalId): array
    {
        $signals = [];
        $message = $this->firstString([
            data_get($order->raw_payload, 'buyerCheckoutNotes'),
            data_get($order->raw_payload, 'buyerMessage'),
            data_get($order->raw_payload, 'buyer.buyerMessage'),
        ]);

        foreach ($order->lines as $line) {
            $listingId = $line->listing_id
                ?? $listingIdByExternalId->get($line->external_listing_id);

            if ($listingId === null || ! $listingById->has($listingId)) {
                continue;
            }

            $listing = $listingById->get($listingId);
            $title = $listing?->title ?? $line->title ?? $line->external_listing_id;

            if ($message !== null) {
                $signals[] = $this->buyerQuestionSignal($order, $listingId, $title, $message);
            }

            if ($this->hasReturnOrCancelSignal($order)) {
                $signals[] = $this->returnOrCancelSignal($order, $listingId, $title);
            }
        }

        return $signals;
    }

    /**
     * @return array<string, mixed>
     */
    private function buyerQuestionSignal(Order $order, int $listingId, ?string $title, string $message): array
    {
        return [
            'listing_id' => $listingId,
            'listing_title' => $title,
            'buyer' => $order->buyer_username,
            'ordered_at' => $order->ordered_at,
            'type' => 'buyer_question',
            'label' => 'Buyer question',
            'detail' => Str::limit($message, 120, '...'),
            'severity' => 'warning',
            'severity_score' => 30,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function returnOrCancelSignal(Order $order, int $listingId, ?string $title): array
    {
        $status = strtoupper((string) $order->status);

        return [
            'listing_id' => $listingId,
            'listing_title' => $title,
            'buyer' => $order->buyer_username,
            'ordered_at' => $order->ordered_at,
            'type' => 'return_or_cancel',
            'label' => 'Return / cancellation signal',
            'detail' => $status !== '' ? $status : 'Return or cancel signal found in eBay order payload.',
            'severity' => 'danger',
            'severity_score' => 45,
        ];
    }

    private function hasReturnOrCancelSignal(Order $order): bool
    {
        $haystacks = [
            strtoupper((string) $order->status),
            strtoupper((string) data_get($order->raw_payload, 'cancelStatus')),
            strtoupper((string) data_get($order->raw_payload, 'returnStatus')),
            strtoupper((string) data_get($order->raw_payload, 'orderPaymentStatus')),
            strtoupper((string) data_get($order->raw_payload, 'orderFulfillmentStatus')),
        ];

        return collect($haystacks)->contains(fn (string $value): bool => Str::contains($value, ['RETURN', 'REFUND', 'CANCEL']));
    }

    /**
     * @param  list<mixed>  $candidates
     */
    private function firstString(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }
}
