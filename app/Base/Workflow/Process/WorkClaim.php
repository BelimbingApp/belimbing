<?php

namespace App\Base\Workflow\Process;

use App\Base\Workflow\Models\ProcessWorkItem;

final readonly class WorkClaim
{
    public function __construct(
        public ProcessWorkItem $workItem,
        public string $leaseToken,
    ) {}
}
