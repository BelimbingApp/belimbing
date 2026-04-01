<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\Services\Memory\MemoryChunker;
use App\Modules\Core\AI\Services\Memory\MemoryCompactor;
use App\Modules\Core\AI\Services\Memory\MemoryIndexer;
use App\Modules\Core\AI\Services\Memory\MemorySourceCatalog;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    $this->workspacePath = storage_path('framework/testing/memory-compactor-'.uniqid());
    config()->set('ai.workspace_path', $this->workspacePath);
    config()->set('ai.memory.max_chunk_chars', 2000);
    config()->set('ai.memory.compaction_archive_prefix', 'archived-');
    $this->agentId = 99;
    $this->agentDir = $this->workspacePath.'/'.$this->agentId;
});

afterEach(function (): void {
    File::deleteDirectory($this->workspacePath);
});

function makeCompactor(): MemoryCompactor
{
    $catalog = new MemorySourceCatalog;
    $indexer = new MemoryIndexer($catalog, new MemoryChunker);

    return new MemoryCompactor($catalog, $indexer);
}

it('returns zeros when no memory directory exists', function (): void {
    $result = makeCompactor()->compact($this->agentId);

    expect($result['compacted_files'])->toBe(0)
        ->and($result['archived_files'])->toBe(0)
        ->and($result['appended_bytes'])->toBe(0);
});

it('returns zeros when no daily files exist', function (): void {
    File::ensureDirectoryExists($this->agentDir.'/memory');

    $result = makeCompactor()->compact($this->agentId);

    expect($result['compacted_files'])->toBe(0);
});

it('compacts daily files into MEMORY.md', function (): void {
    File::ensureDirectoryExists($this->agentDir.'/memory');
    file_put_contents($this->agentDir.'/memory/2026-07-20.md', 'Important fact from day 1.');
    file_put_contents($this->agentDir.'/memory/2026-07-21.md', 'Another fact from day 2.');

    $result = makeCompactor()->compact($this->agentId);

    expect($result['compacted_files'])->toBe(2)
        ->and($result['archived_files'])->toBe(2)
        ->and($result['appended_bytes'])->toBeGreaterThan(0);

    // MEMORY.md should have been created with compacted content
    $durableContent = file_get_contents($this->agentDir.'/MEMORY.md');
    expect($durableContent)->toContain('Important fact from day 1')
        ->and($durableContent)->toContain('Another fact from day 2')
        ->and($durableContent)->toContain('Agent Memory');
});

it('archives daily files with prefix', function (): void {
    File::ensureDirectoryExists($this->agentDir.'/memory');
    file_put_contents($this->agentDir.'/memory/note.md', 'Some note.');

    makeCompactor()->compact($this->agentId);

    // Original should be gone, archived version should exist
    expect(is_file($this->agentDir.'/memory/note.md'))->toBeFalse()
        ->and(is_file($this->agentDir.'/memory/archived-note.md'))->toBeTrue();
});

it('skips already archived files', function (): void {
    File::ensureDirectoryExists($this->agentDir.'/memory');
    file_put_contents($this->agentDir.'/memory/archived-old.md', 'Previously archived.');
    file_put_contents($this->agentDir.'/memory/new.md', 'New content.');

    $result = makeCompactor()->compact($this->agentId);

    // Only the new file should be compacted
    expect($result['compacted_files'])->toBe(1);
});

it('appends to existing MEMORY.md', function (): void {
    File::ensureDirectoryExists($this->agentDir.'/memory');
    file_put_contents($this->agentDir.'/MEMORY.md', "# Existing Memory\n\nPre-existing knowledge.");
    file_put_contents($this->agentDir.'/memory/new.md', 'New fact.');

    makeCompactor()->compact($this->agentId);

    $content = file_get_contents($this->agentDir.'/MEMORY.md');
    expect($content)->toContain('Pre-existing knowledge')
        ->and($content)->toContain('New fact.');
});

it('handles empty daily files gracefully', function (): void {
    File::ensureDirectoryExists($this->agentDir.'/memory');
    file_put_contents($this->agentDir.'/memory/empty.md', '');

    $result = makeCompactor()->compact($this->agentId);

    // File was reviewed but no content to append
    expect($result['compacted_files'])->toBe(1)
        ->and($result['appended_bytes'])->toBe(0)
        ->and($result['archived_files'])->toBe(1);
});

it('triggers reindex after compaction', function (): void {
    File::ensureDirectoryExists($this->agentDir.'/memory');
    file_put_contents($this->agentDir.'/memory/note.md', 'Fact to remember.');

    makeCompactor()->compact($this->agentId);

    // The SQLite index should exist after reindex
    expect(is_file($this->agentDir.'/memory.sqlite'))->toBeTrue();
});
