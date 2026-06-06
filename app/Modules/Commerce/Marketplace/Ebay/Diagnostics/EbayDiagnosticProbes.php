<?php

namespace App\Modules\Commerce\Marketplace\Ebay\Diagnostics;

/**
 * Catalog of curated read-only eBay diagnostic probes.
 *
 * The default probe preserves the original one-click "connection test": a read
 * of the seller's payment policies via the Account API. Additional probes let
 * operators isolate which endpoint or scope is failing without leaving the
 * settings page.
 */
class EbayDiagnosticProbes
{
    public const DEFAULT_KEY = 'account_payment_policies';

    /**
     * @return array<string, EbayDiagnosticProbe>
     */
    public function all(): array
    {
        $probes = [
            new EbayDiagnosticProbe(
                key: 'account_payment_policies',
                label: __('Account · payment policies'),
                intent: __('Reads your eBay payment business policies through the Account API.'),
                path: '/sell/account/v1/payment_policy',
                scopeGroup: EbayDiagnosticProbe::SCOPE_GROUP_ACCOUNT,
            ),
            new EbayDiagnosticProbe(
                key: 'account_fulfillment_policies',
                label: __('Account · shipping policies'),
                intent: __('Reads your eBay fulfillment (shipping) business policies through the Account API.'),
                path: '/sell/account/v1/fulfillment_policy',
                scopeGroup: EbayDiagnosticProbe::SCOPE_GROUP_ACCOUNT,
            ),
            new EbayDiagnosticProbe(
                key: 'account_return_policies',
                label: __('Account · return policies'),
                intent: __('Reads your eBay return business policies through the Account API.'),
                path: '/sell/account/v1/return_policy',
                scopeGroup: EbayDiagnosticProbe::SCOPE_GROUP_ACCOUNT,
            ),
            new EbayDiagnosticProbe(
                key: 'inventory_locations',
                label: __('Inventory · locations'),
                intent: __('Reads your eBay merchant inventory locations through the Inventory API.'),
                path: '/sell/inventory/v1/location',
                scopeGroup: EbayDiagnosticProbe::SCOPE_GROUP_INVENTORY,
                query: ['limit' => 1],
                marketplaceScoped: false,
            ),
            new EbayDiagnosticProbe(
                key: 'inventory_items',
                label: __('Inventory · items'),
                intent: __('Reads the first page of your eBay inventory items through the Inventory API.'),
                path: '/sell/inventory/v1/inventory_item',
                scopeGroup: EbayDiagnosticProbe::SCOPE_GROUP_INVENTORY,
                query: ['limit' => 1],
                marketplaceScoped: false,
            ),
        ];

        $keyed = [];

        foreach ($probes as $probe) {
            $keyed[$probe->key] = $probe;
        }

        return $keyed;
    }

    public function default(): EbayDiagnosticProbe
    {
        return $this->all()[self::DEFAULT_KEY];
    }

    public function find(?string $key): EbayDiagnosticProbe
    {
        return $this->all()[$key] ?? $this->default();
    }
}
