<?php

namespace App\Base\Database\DTO\DataShare;

use App\Base\Database\Enums\DataShareInstanceRole;
use App\Base\Database\Models\DataShareReceipt;

final readonly class DataSharePackageExpectation
{
    public function __construct(
        public string $offerId,
        public DataShareInstanceIdentity $source,
        public string $scope,
        public string $packageId,
        public string $packageSha256,
        public int $bytes,
        /** @var array{tables: int, records: int} */
        public array $counts,
        public string $expiresAt,
    ) {}

    public static function fromOffer(DataShareTransferOfferBundle $offer): self
    {
        return new self(
            $offer->offerId,
            $offer->source,
            $offer->scope,
            $offer->packageId,
            $offer->packageSha256,
            $offer->bytes,
            $offer->counts,
            $offer->expiresAt,
        );
    }

    public static function fromReceipt(DataShareReceipt $receipt): self
    {
        return new self(
            $receipt->offer_id,
            new DataShareInstanceIdentity(
                $receipt->source_instance_id,
                (string) ($receipt->metadata['source_name'] ?? $receipt->source_instance_id),
                DataShareInstanceRole::from($receipt->source_role),
            ),
            $receipt->scope_name,
            $receipt->package_id,
            $receipt->package_sha256,
            (int) ($receipt->metadata['bytes'] ?? 0),
            (array) ($receipt->metadata['counts'] ?? []),
            $receipt->expires_at->toIso8601String(),
        );
    }
}
