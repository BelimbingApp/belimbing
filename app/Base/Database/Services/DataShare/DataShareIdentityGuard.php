<?php

namespace App\Base\Database\Services\DataShare;

use App\Base\Database\Models\DataShareReceipt;
use App\Base\Database\Models\DataShareTransferOffer;

/**
 * Counts outstanding Data Share work that an identity change would orphan (#896).
 */
final class DataShareIdentityGuard
{
    /** Offer statuses that no longer bind an operator to the published identity. */
    private const array SETTLED_OFFER_STATUSES = [
        'revoked',
        'expired',
        'exhausted',
    ];

    /**
     * @return array{offers: int, unapplied: int}
     */
    public function outstanding(): array
    {
        return [
            'offers' => DataShareTransferOffer::query()
                ->whereNotIn('status', self::SETTLED_OFFER_STATUSES)
                ->where('expires_at', '>', now())
                ->count(),
            'unapplied' => DataShareReceipt::query()
                ->where('status', 'received')
                ->count(),
        ];
    }

    public function hasOutstanding(): bool
    {
        $counts = $this->outstanding();

        return $counts['offers'] > 0 || $counts['unapplied'] > 0;
    }
}
