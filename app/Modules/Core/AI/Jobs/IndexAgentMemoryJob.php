<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Jobs;

use App\Modules\Core\AI\Services\Memory\MemoryIndexer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Queue job to build or refresh the memory index for an agent.
 */
class IndexAgentMemoryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $employeeId,
        public readonly bool $force = false,
    ) {
        $this->onQueue('default');
    }

    public function handle(MemoryIndexer $indexer): void
    {
        if ($this->force) {
            $indexer->reindex($this->employeeId);
        } else {
            $indexer->index($this->employeeId);
        }
    }
}
