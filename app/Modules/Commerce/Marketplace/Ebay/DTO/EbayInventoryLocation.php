<?php
namespace App\Modules\Commerce\Marketplace\Ebay\DTO;

/**
 * One inventory location on the seller's eBay account.
 *
 * Every published `Offer` references a `merchantLocationKey` that points at
 * one of these locations; the publish path can't run until at least one
 * location of an applicable type (e.g. `WAREHOUSE`) is enabled on the seller's
 * account. We only store the fields the operator needs to recognise and pick
 * a location; the full payload is left on eBay's side.
 *
 * `merchantLocationKey` is the string identifier the seller controls (often
 * a slug like `home_warehouse`); it's what the offer publish call references.
 *
 * @property-read list<string> $locationTypes
 */
final readonly class EbayInventoryLocation
{
    public function __construct(
        public string $merchantLocationKey,
        public ?string $name,
        public ?string $status,
        public ?string $country,
        public ?string $postalCode,
        public ?string $city,
        public array $locationTypes,
    ) {}

    public function isEnabled(): bool
    {
        return $this->status === 'ENABLED';
    }
}
