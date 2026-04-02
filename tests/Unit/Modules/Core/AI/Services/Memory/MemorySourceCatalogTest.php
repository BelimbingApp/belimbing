<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\Enums\MemoryFileType;
use App\Modules\Core\AI\Services\Memory\MemorySourceCatalog;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

const MEMORY_SOURCE_DURABLE_FILE = '/MEMORY.md';
const MEMORY_SOURCE_DAILY_DIR = '/memory';
const MEMORY_SOURCE_DURABLE_RELATIVE = 'MEMORY.md';

beforeEach(function (): void {
    $this->workspacePath = storage_path('framework/testing/memory-catalog-'.uniqid());
    config()->set('ai.workspace_path', $this->workspacePath);
    $this->agentId = 99;
    $this->agentDir = $this->workspacePath.'/'.$this->agentId;
});

afterEach(function (): void {
    File::deleteDirectory($this->workspacePath);
});

function makeCatalog(): MemorySourceCatalog
{
    return new MemorySourceCatalog;
}

it('returns empty when workspace does not exist', function (): void {
    $catalog = makeCatalog();

    expect($catalog->scan($this->agentId))->toBe([]);
});

it('discovers MEMORY.md as durable source', function (): void {
    File::ensureDirectoryExists($this->agentDir);
    file_put_contents($this->agentDir.MEMORY_SOURCE_DURABLE_FILE, '# Durable knowledge');

    $sources = makeCatalog()->scan($this->agentId);

    expect($sources)->toHaveCount(1)
        ->and($sources[0]->relativePath)->toBe(MEMORY_SOURCE_DURABLE_RELATIVE)
        ->and($sources[0]->type)->toBe(MemoryFileType::Durable)
        ->and($sources[0]->contentHash)->not->toBeEmpty()
        ->and($sources[0]->size)->toBeGreaterThan(0);
});

it('discovers daily files from memory directory', function (): void {
    File::ensureDirectoryExists($this->agentDir.MEMORY_SOURCE_DAILY_DIR);
    file_put_contents($this->agentDir.'/memory/2026-07-20.md', '# Daily note');
    file_put_contents($this->agentDir.'/memory/2026-07-21.md', '# Another note');

    $sources = makeCatalog()->scan($this->agentId);

    expect($sources)->toHaveCount(2)
        ->and($sources[0]->type)->toBe(MemoryFileType::Daily)
        ->and($sources[1]->type)->toBe(MemoryFileType::Daily);

    $paths = array_map(fn ($s) => $s->relativePath, $sources);
    expect($paths)->toContain('memory/2026-07-20.md', 'memory/2026-07-21.md');
});

it('discovers both durable and daily sources together', function (): void {
    File::ensureDirectoryExists($this->agentDir.MEMORY_SOURCE_DAILY_DIR);
    file_put_contents($this->agentDir.MEMORY_SOURCE_DURABLE_FILE, '# Durable');
    file_put_contents($this->agentDir.'/memory/today.md', '# Today');

    $sources = makeCatalog()->scan($this->agentId);

    expect($sources)->toHaveCount(2);

    $types = array_map(fn ($s) => $s->type, $sources);
    expect($types)->toContain(MemoryFileType::Durable, MemoryFileType::Daily);
});

it('ignores non-md files in memory directory', function (): void {
    File::ensureDirectoryExists($this->agentDir.MEMORY_SOURCE_DAILY_DIR);
    file_put_contents($this->agentDir.'/memory/notes.md', 'valid');
    file_put_contents($this->agentDir.'/memory/image.png', 'binary');
    file_put_contents($this->agentDir.'/memory/data.json', '{}');

    $sources = makeCatalog()->scan($this->agentId);

    expect($sources)->toHaveCount(1)
        ->and($sources[0]->relativePath)->toBe('memory/notes.md');
});

it('does not scan subdirectories recursively', function (): void {
    File::ensureDirectoryExists($this->agentDir.MEMORY_SOURCE_DAILY_DIR.'/nested');
    file_put_contents($this->agentDir.'/memory/top.md', 'top level');
    file_put_contents($this->agentDir.'/memory/nested/deep.md', 'nested');

    $sources = makeCatalog()->scan($this->agentId);

    expect($sources)->toHaveCount(1)
        ->and($sources[0]->relativePath)->toBe('memory/top.md');
});

it('validates memory paths correctly', function (): void {
    $catalog = makeCatalog();

    expect($catalog->isMemoryPath(MEMORY_SOURCE_DURABLE_RELATIVE))->toBeTrue()
        ->and($catalog->isMemoryPath('memory/2026-07-20.md'))->toBeTrue()
        ->and($catalog->isMemoryPath('memory/notes.md'))->toBeTrue()
        ->and($catalog->isMemoryPath('memory/../etc/passwd'))->toBeFalse()
        ->and($catalog->isMemoryPath('other/file.md'))->toBeFalse()
        ->and($catalog->isMemoryPath('memory/nested/deep.md'))->toBeFalse()
        ->and($catalog->isMemoryPath('memory/data.txt'))->toBeFalse()
        ->and($catalog->isMemoryPath('../MEMORY.md'))->toBeFalse();
});

it('resolves read path for existing memory files', function (): void {
    File::ensureDirectoryExists($this->agentDir);
    file_put_contents($this->agentDir.MEMORY_SOURCE_DURABLE_FILE, 'content');

    $catalog = makeCatalog();

    expect($catalog->resolveReadPath($this->agentId, MEMORY_SOURCE_DURABLE_RELATIVE))->toBe($this->agentDir.MEMORY_SOURCE_DURABLE_FILE)
        ->and($catalog->resolveReadPath($this->agentId, 'memory/missing.md'))->toBeNull()
        ->and($catalog->resolveReadPath($this->agentId, '../etc/passwd'))->toBeNull();
});

it('classifies paths by file type', function (): void {
    $catalog = makeCatalog();

    expect($catalog->classifyPath(MEMORY_SOURCE_DURABLE_RELATIVE))->toBe(MemoryFileType::Durable)
        ->and($catalog->classifyPath('memory/2026-07-20.md'))->toBe(MemoryFileType::Daily)
        ->and($catalog->classifyPath('memory/notes.md'))->toBe(MemoryFileType::Daily);
});

it('generates correct content hashes', function (): void {
    File::ensureDirectoryExists($this->agentDir);
    $content = 'Test content for hashing';
    file_put_contents($this->agentDir.MEMORY_SOURCE_DURABLE_FILE, $content);

    $sources = makeCatalog()->scan($this->agentId);

    expect($sources[0]->contentHash)->toBe(hash('sha256', $content));
});
