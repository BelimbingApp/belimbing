<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Jobs;

use App\Modules\Core\AI\Services\Memory\MemoryCompactor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Queue job to compact daily memory notes into durable knowledge for an agent.
 */
class CompactAgentMemoryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $employeeId,
    ) {
        $this->onQueue('default');
    }

    public function handle(MemoryCompactor $compactor): void
    {
        $compactor->compact($this->employeeId);
    }
}
