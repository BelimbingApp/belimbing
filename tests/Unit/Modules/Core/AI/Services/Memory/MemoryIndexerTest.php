<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\Services\Memory\MemoryChunker;
use App\Modules\Core\AI\Services\Memory\MemoryIndexer;
use App\Modules\Core\AI\Services\Memory\MemorySourceCatalog;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    $this->workspacePath = storage_path('framework/testing/memory-indexer-'.uniqid());
    config()->set('ai.workspace_path', $this->workspacePath);
    config()->set('ai.memory.max_chunk_chars', 2000);
    $this->agentId = 99;
    $this->agentDir = $this->workspacePath.'/'.$this->agentId;
});

afterEach(function (): void {
    File::deleteDirectory($this->workspacePath);
});

function makeIndexer(): MemoryIndexer
{
    return new MemoryIndexer(
        new MemorySourceCatalog,
        new MemoryChunker,
    );
}

it('indexes a single memory file', function (): void {
    File::ensureDirectoryExists($this->agentDir);
    file_put_contents($this->agentDir.'/MEMORY.md', "## Knowledge\n\nImportant facts here.");

    $result = makeIndexer()->index($this->agentId);

    expect($result['indexed'])->toBe(1)
        ->and($result['skipped'])->toBe(0)
        ->and($result['stale_removed'])->toBe(0)
        ->and($result['total_chunks'])->toBeGreaterThan(0);
});

it('skips unchanged files on second index', function (): void {
    File::ensureDirectoryExists($this->agentDir);
    file_put_contents($this->agentDir.'/MEMORY.md', "## Facts\n\nSome content.");

    makeIndexer()->index($this->agentId);
    $result = makeIndexer()->index($this->agentId);

    expect($result['indexed'])->toBe(0)
        ->and($result['skipped'])->toBe(1);
});

it('re-indexes changed files', function (): void {
    File::ensureDirectoryExists($this->agentDir);
    file_put_contents($this->agentDir.'/MEMORY.md', "## Facts\n\nOriginal.");

    makeIndexer()->index($this->agentId);

    file_put_contents($this->agentDir.'/MEMORY.md', "## Facts\n\nUpdated content.");
    $result = makeIndexer()->index($this->agentId);

    expect($result['indexed'])->toBe(1)
        ->and($result['skipped'])->toBe(0);
});

it('removes stale entries for deleted files', function (): void {
    File::ensureDirectoryExists($this->agentDir.'/memory');
    file_put_contents($this->agentDir.'/memory/day1.md', 'Day 1 notes');
    file_put_contents($this->agentDir.'/memory/day2.md', 'Day 2 notes');

    makeIndexer()->index($this->agentId);

    // Delete day2
    unlink($this->agentDir.'/memory/day2.md');

    $result = makeIndexer()->index($this->agentId);

    expect($result['stale_removed'])->toBe(1);
});

it('force reindexes all files', function (): void {
    File::ensureDirectoryExists($this->agentDir);
    file_put_contents($this->agentDir.'/MEMORY.md', "## Facts\n\nContent.");

    makeIndexer()->index($this->agentId);
    $result = makeIndexer()->reindex($this->agentId);

    // Force reindex always indexes, never skips
    expect($result['indexed'])->toBe(1)
        ->and($result['skipped'])->toBe(0);
});

it('handles empty workspace gracefully', function (): void {
    $result = makeIndexer()->index($this->agentId);

    expect($result['indexed'])->toBe(0)
        ->and($result['skipped'])->toBe(0)
        ->and($result['total_chunks'])->toBe(0);
});

it('indexes multiple files across durable and daily', function (): void {
    File::ensureDirectoryExists($this->agentDir.'/memory');
    file_put_contents($this->agentDir.'/MEMORY.md', "## Durable\n\nLong-term memory.");
    file_put_contents($this->agentDir.'/memory/2026-07-20.md', "## Today\n\nToday's notes and observations.");

    $result = makeIndexer()->index($this->agentId);

    expect($result['indexed'])->toBe(2)
        ->and($result['total_chunks'])->toBeGreaterThanOrEqual(2);
});
