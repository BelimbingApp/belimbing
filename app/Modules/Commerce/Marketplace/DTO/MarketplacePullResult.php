<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Marketplace\DTO;

final readonly class MarketplacePullResult
{
    /**
     * @param  list<string>  $warnings
     */
    public function __construct(
        public string $channel,
        public int $fetched,
        public int $created,
        public int $updated,
        public int $linked,
        public array $warnings = [],
    ) {}
}
