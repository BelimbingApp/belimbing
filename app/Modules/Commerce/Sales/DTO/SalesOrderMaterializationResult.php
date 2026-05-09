<?php
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
