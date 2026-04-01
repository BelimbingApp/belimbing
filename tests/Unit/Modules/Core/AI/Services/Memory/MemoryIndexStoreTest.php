<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\DTO\MemoryChunk;
use App\Modules\Core\AI\DTO\MemoryIndexManifestEntry;
use App\Modules\Core\AI\Services\Memory\MemoryIndexStore;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    $this->dbDir = storage_path('framework/testing/memory-store-'.uniqid());
    File::ensureDirectoryExists($this->dbDir);
    $this->dbPath = $this->dbDir.'/memory.sqlite';
    $this->store = new MemoryIndexStore($this->dbPath);
    $this->store->ensureSchema();
});

afterEach(function (): void {
    File::deleteDirectory($this->dbDir);
});

it('creates schema without errors', function (): void {
    expect(is_file($this->dbPath))->toBeTrue();
    expect($this->store->chunkCount())->toBe(0);
});

it('inserts and retrieves chunks', function (): void {
    $chunks = [
        new MemoryChunk(
            sourceRelativePath: 'MEMORY.md',
            sourceHash: 'abc',
            heading: 'Test Heading',
            content: 'Test content about Laravel configuration.',
            fingerprint: hash('sha256', 'Test content about Laravel configuration.'),
            order: 0,
        ),
    ];

    $this->store->insertChunks($chunks);

    expect($this->store->chunkCount())->toBe(1);
});

it('deletes chunks for a source', function (): void {
    $chunks = [
        new MemoryChunk('MEMORY.md', 'abc', 'H1', 'Content 1', 'fp1', 0),
        new MemoryChunk('MEMORY.md', 'abc', 'H2', 'Content 2', 'fp2', 1),
        new MemoryChunk('memory/daily.md', 'def', 'H3', 'Content 3', 'fp3', 0),
    ];

    $this->store->insertChunks($chunks);
    expect($this->store->chunkCount())->toBe(3);

    $this->store->deleteChunksForSource('MEMORY.md');
    expect($this->store->chunkCount())->toBe(1);
});

it('manages manifest entries', function (): void {
    $entry = new MemoryIndexManifestEntry(
        relativePath: 'MEMORY.md',
        contentHash: 'abc123',
        chunkCount: 5,
        indexedAt: time(),
    );

    $this->store->upsertManifestEntry($entry);

    $retrieved = $this->store->manifestEntry('MEMORY.md');
    expect($retrieved)->not->toBeNull()
        ->and($retrieved->contentHash)->toBe('abc123')
        ->and($retrieved->chunkCount)->toBe(5);
});

it('updates manifest on upsert', function (): void {
    $entry1 = new MemoryIndexManifestEntry('MEMORY.md', 'hash1', 3, time());
    $this->store->upsertManifestEntry($entry1);

    $entry2 = new MemoryIndexManifestEntry('MEMORY.md', 'hash2', 7, time());
    $this->store->upsertManifestEntry($entry2);

    $retrieved = $this->store->manifestEntry('MEMORY.md');
    expect($retrieved->contentHash)->toBe('hash2')
        ->and($retrieved->chunkCount)->toBe(7);
});

it('returns null for missing manifest entry', function (): void {
    expect($this->store->manifestEntry('nonexistent.md'))->toBeNull();
});

it('lists all manifest entries', function (): void {
    $this->store->upsertManifestEntry(new MemoryIndexManifestEntry('a.md', 'h1', 1, time()));
    $this->store->upsertManifestEntry(new MemoryIndexManifestEntry('b.md', 'h2', 2, time()));

    $entries = $this->store->allManifestEntries();
    expect($entries)->toHaveCount(2);
});

it('removes stale entries', function (): void {
    $this->store->insertChunks([
        new MemoryChunk('a.md', 'h1', 'H', 'C1', 'fp1', 0),
        new MemoryChunk('b.md', 'h2', 'H', 'C2', 'fp2', 0),
    ]);
    $this->store->upsertManifestEntry(new MemoryIndexManifestEntry('a.md', 'h1', 1, time()));
    $this->store->upsertManifestEntry(new MemoryIndexManifestEntry('b.md', 'h2', 1, time()));

    // Only a.md still exists
    $removed = $this->store->removeStaleEntries(['a.md']);

    expect($removed)->toBe(1)
        ->and($this->store->manifestEntry('b.md'))->toBeNull()
        ->and($this->store->chunkCount())->toBe(1);
});

it('performs keyword search with scoring', function (): void {
    $this->store->insertChunks([
        new MemoryChunk('MEMORY.md', 'h', 'Laravel Config', 'How to configure database settings in Laravel.', 'fp1', 0),
        new MemoryChunk('MEMORY.md', 'h', 'User Auth', 'Authentication setup for user login.', 'fp2', 1),
        new MemoryChunk('MEMORY.md', 'h', 'API Routes', 'Define API routes in routes file.', 'fp3', 2),
    ]);

    $results = $this->store->keywordSearch(['laravel', 'config'], 10);

    // First result should be about Laravel Config (heading + content match)
    expect($results)->not->toBeEmpty()
        ->and($results[0]['heading'])->toBe('Laravel Config')
        ->and($results[0]['score'])->toBeGreaterThan(0);
});

it('returns empty for search with no matching tokens', function (): void {
    $this->store->insertChunks([
        new MemoryChunk('MEMORY.md', 'h', 'Title', 'Content here', 'fp1', 0),
    ]);

    $results = $this->store->keywordSearch(['zzzznonexistent'], 10);

    expect($results)->toBe([]);
});

it('returns empty for search with no tokens', function (): void {
    expect($this->store->keywordSearch([], 10))->toBe([]);
});

it('manages meta values', function (): void {
    $this->store->setMeta('last_indexed_at', '1234567890');

    expect($this->store->getMeta('last_indexed_at'))->toBe('1234567890')
        ->and($this->store->getMeta('nonexistent'))->toBeNull();
});

it('upserts meta values', function (): void {
    $this->store->setMeta('key', 'value1');
    $this->store->setMeta('key', 'value2');

    expect($this->store->getMeta('key'))->toBe('value2');
});

it('supports transactions', function (): void {
    $this->store->beginTransaction();
    $this->store->insertChunks([
        new MemoryChunk('test.md', 'h', 'H', 'Content', 'fp', 0),
    ]);
    $this->store->commit();

    expect($this->store->chunkCount())->toBe(1);
});

it('rolls back transactions', function (): void {
    $this->store->beginTransaction();
    $this->store->insertChunks([
        new MemoryChunk('test.md', 'h', 'H', 'Content', 'fp', 0),
    ]);
    $this->store->rollBack();

    expect($this->store->chunkCount())->toBe(0);
});
