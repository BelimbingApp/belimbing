<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Marketplace\Ebay\DTO;

/**
 * One reusable business policy on the seller's eBay account.
 *
 * The seller maintains payment, fulfillment, and return policies separately on
 * eBay; each Offer references one of each by `policyId`. We pull these once at
 * onboarding (and refresh on demand) so the operator can pick defaults to
 * embed in every BLB-published listing without re-typing eBay vocabulary.
 *
 * `kind` is one of `payment`, `fulfillment`, `return` — tagged on the DTO so
 * mixed lists can be sorted/filtered without losing provenance.
 */
final readonly class EbayBusinessPolicy
{
    public const string KIND_PAYMENT = 'payment';

    public const string KIND_FULFILLMENT = 'fulfillment';

    public const string KIND_RETURN = 'return';

    public function __construct(
        public string $kind,
        public string $id,
        public string $name,
        public string $marketplaceId,
        public ?string $description,
    ) {}
}
