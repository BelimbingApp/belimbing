<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\Services\Memory\MemoryChunker;
use App\Modules\Core\AI\Services\Memory\MemoryHealthService;
use App\Modules\Core\AI\Services\Memory\MemoryIndexer;
use App\Modules\Core\AI\Services\Memory\MemorySourceCatalog;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

const MEMORY_HEALTH_DURABLE_FILE = '/MEMORY.md';

beforeEach(function (): void {
    $this->workspacePath = storage_path('framework/testing/memory-health-'.uniqid());
    config()->set('ai.workspace_path', $this->workspacePath);
    config()->set('ai.memory.max_chunk_chars', 2000);
    $this->agentId = 99;
    $this->agentDir = $this->workspacePath.'/'.$this->agentId;
});

afterEach(function (): void {
    File::deleteDirectory($this->workspacePath);
});

function makeHealthService(): MemoryHealthService
{
    return new MemoryHealthService(new MemorySourceCatalog);
}

it('reports not indexed when no index exists', function (): void {
    $report = makeHealthService()->report($this->agentId);

    expect($report->indexed)->toBeFalse()
        ->and($report->employeeId)->toBe($this->agentId)
        ->and($report->sourceCount)->toBe(0)
        ->and($report->chunkCount)->toBe(0)
        ->and($report->lastIndexedAt)->toBeNull();
});

it('reports sources without index as all stale', function (): void {
    File::ensureDirectoryExists($this->agentDir);
    file_put_contents($this->agentDir.MEMORY_HEALTH_DURABLE_FILE, 'Some knowledge.');

    $report = makeHealthService()->report($this->agentId);

    expect($report->indexed)->toBeFalse()
        ->and($report->sourceCount)->toBe(1)
        ->and($report->staleSourceCount)->toBe(1);
});

it('reports healthy indexed state', function (): void {
    File::ensureDirectoryExists($this->agentDir);
    file_put_contents($this->agentDir.MEMORY_HEALTH_DURABLE_FILE, "## Facts\n\nImportant knowledge.");

    $indexer = new MemoryIndexer(new MemorySourceCatalog, new MemoryChunker);
    $indexer->index($this->agentId);

    $report = makeHealthService()->report($this->agentId);

    expect($report->indexed)->toBeTrue()
        ->and($report->sourceCount)->toBe(1)
        ->and($report->chunkCount)->toBeGreaterThan(0)
        ->and($report->staleSourceCount)->toBe(0)
        ->and($report->lastIndexedAt)->not->toBeNull();
});

it('detects stale sources after file changes', function (): void {
    File::ensureDirectoryExists($this->agentDir);
    file_put_contents($this->agentDir.MEMORY_HEALTH_DURABLE_FILE, 'Original content.');

    $indexer = new MemoryIndexer(new MemorySourceCatalog, new MemoryChunker);
    $indexer->index($this->agentId);

    // Modify the file after indexing
    file_put_contents($this->agentDir.MEMORY_HEALTH_DURABLE_FILE, 'Modified content.');

    $report = makeHealthService()->report($this->agentId);

    expect($report->staleSourceCount)->toBe(1);
});

it('includes embedding availability', function (): void {
    $report = makeHealthService()->report($this->agentId);

    expect($report->embeddingsAvailable)->toBeBool();
});
