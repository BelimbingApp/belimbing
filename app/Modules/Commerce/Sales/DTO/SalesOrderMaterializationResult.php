<?php

namespace App\Modules\Commerce\Sales\DTO;

use App\Modules\Commerce\Sales\Models\Order;

final readonly class SalesOrderMaterializationResult
{
    /**
     * @param  list<int>  $affectedItemIds  Inventory items whose quantity changed from newly ingested sale lines
     */
    public function __construct(
        public Order $order,
        public bool $created,
        public int $lineCount,
        public int $linkedCount,
        public array $affectedItemIds = [],
    ) {}
}
