<?php

namespace App\Modules\Commerce\Marketplace\Ebay;

use App\Base\Foundation\ValueObjects\Money;
use App\Modules\Commerce\Sales\DTO\SalesOrderData;
use App\Modules\Commerce\Sales\DTO\SalesOrderLineData;
use Illuminate\Support\Carbon;

class EbayOrderMapper
{
    /**
     * @param  array<string, mixed>  $order
     */
    public function orderData(string $channel, array $order): SalesOrderData
    {
        $total = $this->amount($order['pricingSummary']['total'] ?? null);
        $lineItems = $order['lineItems'] ?? [];

        return new SalesOrderData(
            channel: $channel,
            externalOrderId: (string) ($order['orderId'] ?? ''),
            marketplaceId: $this->orderMarketplaceId($order),
            buyerUsername: $this->nullableString($order['buyer']['username'] ?? null),
            buyerEmail: $this->nullableString($order['buyer']['email'] ?? $order['buyer']['buyerRegistrationAddress']['email'] ?? null),
            status: $this->nullableString($order['orderPaymentStatus'] ?? $order['orderFulfillmentStatus'] ?? null),
            orderedAt: $this->date($order['creationDate'] ?? null),
            paidAt: $this->paymentDate($order),
            fulfilledAt: ($order['orderFulfillmentStatus'] ?? null) === 'FULFILLED'
                ? $this->date($order['lastModifiedDate'] ?? null)
                : null,
            totalAmount: $total?->minorAmount,
            currencyCode: $total?->currencyCode,
            lines: array_map(fn (array $lineItem): SalesOrderLineData => $this->lineData($lineItem), $lineItems),
            rawPayload: $order,
        );
    }

    /**
     * @param  array<string, mixed>  $lineItem
     */
    private function lineData(array $lineItem): SalesOrderLineData
    {
        $unitPrice = $this->amount($lineItem['lineItemCost'] ?? null);
        $lineTotal = $this->amount($lineItem['total'] ?? null) ?? $unitPrice;

        return new SalesOrderLineData(
            externalLineItemId: $this->nullableString($lineItem['lineItemId'] ?? null),
            externalListingId: $this->nullableString($lineItem['legacyItemId'] ?? $lineItem['listingId'] ?? $lineItem['itemId'] ?? null),
            externalSku: $this->nullableString($lineItem['sku'] ?? null),
            title: $this->nullableString($lineItem['title'] ?? null),
            quantity: max(1, (int) ($lineItem['quantity'] ?? 1)),
            unitPriceAmount: $unitPrice?->minorAmount,
            lineTotalAmount: $lineTotal?->minorAmount,
            currencyCode: $lineTotal?->currencyCode ?? $unitPrice?->currencyCode,
            rawPayload: $lineItem,
        );
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function orderMarketplaceId(array $order): ?string
    {
        foreach ($order['lineItems'] ?? [] as $lineItem) {
            $marketplaceId = $this->nullableString($lineItem['listingMarketplaceId'] ?? null);

            if ($marketplaceId !== null) {
                return $marketplaceId;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function paymentDate(array $order): ?Carbon
    {
        foreach ($order['paymentSummary']['payments'] ?? [] as $payment) {
            $paidAt = $this->date($payment['paymentDate'] ?? null);

            if ($paidAt !== null) {
                return $paidAt;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|mixed  $amount
     */
    private function amount(mixed $amount): ?Money
    {
        if (! is_array($amount)) {
            return null;
        }

        $value = $this->nullableString($amount['value'] ?? null);
        $currency = $this->nullableString($amount['currency'] ?? null);

        return $value !== null && $currency !== null
            ? Money::fromDecimalString($value, $currency)
            : null;
    }

    private function date(mixed $value): ?Carbon
    {
        return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
