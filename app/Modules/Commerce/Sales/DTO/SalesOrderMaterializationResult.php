<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Sales\DTO;

use App\Modules\Commerce\Sales\Models\Order;

final readonly class SalesOrderMaterializationResult
{
    public function __construct(
        public Order $order,
        public bool $created,
        public int $lineCount,
        public int $linkedCount,
    ) {}
}
