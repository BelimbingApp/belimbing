<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Console\Commands;

use App\Modules\Core\AI\Services\Memory\MemoryIndexer;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Build or refresh the memory index for an agent.
 *
 * Only re-indexes sources whose content hash differs from the manifest.
 * Use --force to reindex all sources regardless of hash.
 */
#[AsCommand(name: 'blb:ai:memory:index')]
class MemoryIndexCommand extends Command
{
    protected $description = 'Build or refresh the memory index for an agent';

    protected $signature = 'blb:ai:memory:index
                            {agent : Agent employee ID}
                            {--force : Force full reindex regardless of content hashes}';

    public function handle(MemoryIndexer $indexer): int
    {
        $employeeId = (int) $this->argument('agent');

        $this->components->info("Indexing memory for agent #{$employeeId}...");

        try {
            $result = $this->option('force')
                ? $indexer->reindex($employeeId)
                : $indexer->index($employeeId);
        } catch (\Throwable $e) {
            $this->components->error('Index failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Done: {$result['indexed']} indexed, {$result['skipped']} skipped, {$result['stale_removed']} stale removed, {$result['total_chunks']} total chunks.");

        return self::SUCCESS;
    }
}
