<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Console\Commands;

use App\Modules\Core\AI\Services\Memory\MemoryCompactor;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Compact daily memory notes into durable knowledge.
 *
 * Reads unarchived daily files from memory/, extracts content,
 * appends to MEMORY.md, archives originals, and triggers reindex.
 */
#[AsCommand(name: 'blb:ai:memory:compact')]
class MemoryCompactCommand extends Command
{
    protected $description = 'Compact daily memory notes into durable knowledge for an agent';

    protected $signature = 'blb:ai:memory:compact
                            {agent : Agent employee ID}';

    public function handle(MemoryCompactor $compactor): int
    {
        $employeeId = (int) $this->argument('agent');

        $this->components->info("Compacting memory for agent #{$employeeId}...");

        try {
            $result = $compactor->compact($employeeId);
        } catch (\Throwable $e) {
            $this->components->error('Compaction failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Done: {$result['compacted_files']} files processed, {$result['archived_files']} archived, {$result['appended_bytes']} bytes appended to MEMORY.md.");

        return self::SUCCESS;
    }
}
