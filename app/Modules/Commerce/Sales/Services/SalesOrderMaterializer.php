<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Sales\Services;

use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Commerce\Marketplace\Models\Listing;
use App\Modules\Commerce\Sales\DTO\SalesOrderData;
use App\Modules\Commerce\Sales\DTO\SalesOrderLineData;
use App\Modules\Commerce\Sales\DTO\SalesOrderMaterializationResult;
use App\Modules\Commerce\Sales\Models\Order;
use App\Modules\Commerce\Sales\Models\OrderLine;
use App\Modules\Commerce\Sales\Models\Sale;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SalesOrderMaterializer
{
    public function materialize(int $companyId, SalesOrderData $data): SalesOrderMaterializationResult
    {
        return DB::transaction(function () use ($companyId, $data): SalesOrderMaterializationResult {
            $order = Order::query()->firstOrNew([
                'company_id' => $companyId,
                'channel' => $data->channel,
                'external_order_id' => $data->externalOrderId,
            ]);

            $created = ! $order->exists;

            $order->fill([
                'marketplace_id' => $data->marketplaceId,
                'buyer_username' => $data->buyerUsername,
                'buyer_email' => $data->buyerEmail,
                'status' => $data->status,
                'ordered_at' => $data->orderedAt,
                'paid_at' => $data->paidAt,
                'fulfilled_at' => $data->fulfilledAt,
                'total_amount' => $data->totalAmount,
                'currency_code' => $data->currencyCode !== null ? strtoupper($data->currencyCode) : null,
                'last_synced_at' => Carbon::now(),
                'raw_payload' => $data->rawPayload,
            ]);
            $order->save();

            $linkedCount = 0;

            foreach ($data->lines as $lineData) {
                $line = $this->materializeLine($companyId, $order, $data, $lineData);
                $this->materializeSale($companyId, $order, $line, $data, $lineData);

                if ($line->item_id !== null) {
                    $linkedCount++;
                    $line->item?->forceFill(['status' => Item::STATUS_SOLD])->save();
                }
            }

            return new SalesOrderMaterializationResult(
                $order->refresh(),
                $created,
                count($data->lines),
                $linkedCount,
            );
        });
    }

    private function materializeLine(
        int $companyId,
        Order $order,
        SalesOrderData $orderData,
        SalesOrderLineData $lineData,
    ): OrderLine {
        $listing = $this->matchListing($companyId, $orderData->channel, $lineData);
        $item = $listing?->item ?? $this->matchItem($companyId, $lineData);
        $externalLineItemId = $this->externalLineItemId($orderData, $lineData);

        return OrderLine::query()->updateOrCreate(
            [
                'order_id' => $order->id,
                'external_line_item_id' => $externalLineItemId,
            ],
            [
                'company_id' => $companyId,
                'item_id' => $item?->id,
                'listing_id' => $listing?->id,
                'external_listing_id' => $lineData->externalListingId,
                'external_sku' => $this->normalizedSku($lineData->externalSku),
                'title' => $lineData->title,
                'quantity' => max(1, $lineData->quantity),
                'unit_price_amount' => $lineData->unitPriceAmount,
                'line_total_amount' => $lineData->lineTotalAmount,
                'currency_code' => $lineData->currencyCode !== null ? strtoupper($lineData->currencyCode) : null,
                'raw_payload' => $lineData->rawPayload,
            ],
        );
    }

    private function materializeSale(
        int $companyId,
        Order $order,
        OrderLine $line,
        SalesOrderData $orderData,
        SalesOrderLineData $lineData,
    ): Sale {
        $costBasisAmount = $line->item?->unit_cost_amount !== null
            ? $line->item->unit_cost_amount * max(1, $line->quantity)
            : null;

        return Sale::query()->updateOrCreate(
            ['order_line_id' => $line->id],
            [
                'company_id' => $companyId,
                'order_id' => $order->id,
                'item_id' => $line->item_id,
                'listing_id' => $line->listing_id,
                'channel' => $orderData->channel,
                'external_sale_id' => $orderData->externalOrderId.':'.$this->externalLineItemId($orderData, $lineData),
                'sold_at' => $orderData->paidAt ?? $orderData->orderedAt,
                'quantity' => max(1, $line->quantity),
                'sale_amount' => $line->line_total_amount,
                'currency_code' => $line->currency_code,
                'cost_basis_amount' => $costBasisAmount,
                'fee_amount' => null,
                'raw_payload' => $lineData->rawPayload,
            ],
        );
    }

    private function matchListing(int $companyId, string $channel, SalesOrderLineData $lineData): ?Listing
    {
        $query = Listing::query()
            ->where('company_id', $companyId)
            ->where('channel', $channel);

        if ($lineData->externalListingId !== null && $lineData->externalListingId !== '') {
            return $query->where('external_listing_id', $lineData->externalListingId)->first();
        }

        $sku = $this->normalizedSku($lineData->externalSku);

        if ($sku === null) {
            return null;
        }

        return $query->where('external_sku', $sku)->first();
    }

    private function matchItem(int $companyId, SalesOrderLineData $lineData): ?Item
    {
        $sku = $this->normalizedSku($lineData->externalSku);

        if ($sku === null) {
            return null;
        }

        return Item::query()
            ->where('company_id', $companyId)
            ->where('sku', $sku)
            ->first();
    }

    private function externalLineItemId(SalesOrderData $orderData, SalesOrderLineData $lineData): string
    {
        if ($lineData->externalLineItemId !== null && $lineData->externalLineItemId !== '') {
            return $lineData->externalLineItemId;
        }

        if ($lineData->externalListingId !== null && $lineData->externalListingId !== '') {
            return $lineData->externalListingId;
        }

        if ($lineData->externalSku !== null && $lineData->externalSku !== '') {
            return $this->normalizedSku($lineData->externalSku) ?? $lineData->externalSku;
        }

        return sha1($orderData->externalOrderId.'|'.($lineData->title ?? '').'|'.$lineData->quantity);
    }

    private function normalizedSku(?string $sku): ?string
    {
        $sku = trim((string) $sku);

        return $sku !== '' ? strtoupper($sku) : null;
    }
}
