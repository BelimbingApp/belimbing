<?php

namespace App\Modules\Commerce\Marketplace\Ebay\Diagnostics;

/**
 * A curated, read-only eBay Sell API probe used for endpoint diagnostics.
 *
 * Probes are deliberately GET-only: the method is not a constructor argument
 * but a fixed accessor, so the type itself guarantees a probe can never mutate
 * seller data. Each probe also declares the scope group it needs, letting the
 * diagnostics service pre-flight the granted OAuth token before calling eBay
 * and explain a missing grant instead of surfacing a raw 403.
 *
 * @see EbayDiagnosticProbes for the catalog of available probes.
 */
final readonly class EbayDiagnosticProbe
{
    public const SCOPE_GROUP_ACCOUNT = 'sell.account';

    public const SCOPE_GROUP_INVENTORY = 'sell.inventory';

    /**
     * @param  string  $key  Stable identifier persisted with the result and selected by the operator.
     * @param  string  $label  Operator-facing name (e.g. "Account · payment policies").
     * @param  string  $intent  One-line description of what the probe verifies.
     * @param  string  $path  API path appended to the environment base URL (e.g. /sell/account/v1/payment_policy).
     * @param  string  $scopeGroup  One of the SCOPE_GROUP_* constants.
     * @param  array<string, scalar>  $query  Extra query parameters beyond marketplace_id.
     * @param  bool  $marketplaceScoped  Whether marketplace_id must be sent (Account API) or not (Inventory API).
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $intent,
        public string $path,
        public string $scopeGroup,
        public array $query = [],
        public bool $marketplaceScoped = true,
    ) {}

    /**
     * Probes are read-only by construction.
     */
    public function method(): string
    {
        return 'GET';
    }

    public function operation(): string
    {
        return 'commerce.marketplace.ebay.diagnostics.'.$this->key;
    }
}
