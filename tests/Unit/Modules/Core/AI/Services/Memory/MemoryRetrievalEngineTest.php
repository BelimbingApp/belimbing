<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\Enums\MemoryRetrievalBasis;
use App\Modules\Core\AI\Services\Memory\MemoryChunker;
use App\Modules\Core\AI\Services\Memory\MemoryIndexer;
use App\Modules\Core\AI\Services\Memory\MemoryRetrievalEngine;
use App\Modules\Core\AI\Services\Memory\MemorySourceCatalog;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    $this->workspacePath = storage_path('framework/testing/memory-retrieval-'.uniqid());
    config()->set('ai.workspace_path', $this->workspacePath);
    config()->set('ai.memory.max_chunk_chars', 2000);
    config()->set('ai.memory.default_max_results', 10);
    config()->set('ai.memory.min_score_threshold', 0.05);
    $this->agentId = 99;
    $this->agentDir = $this->workspacePath.'/'.$this->agentId;
});

afterEach(function (): void {
    File::deleteDirectory($this->workspacePath);
});

function seedAndIndex(string $agentDir, int $agentId): void
{
    File::ensureDirectoryExists($agentDir);
    file_put_contents($agentDir.'/MEMORY.md', <<<'MD'
## Laravel Configuration

How to configure database connections and environment variables in Laravel.

## Deployment Process

Steps for deploying to production with zero-downtime using Forge.

## Testing Strategy

Use Pest for unit tests, feature tests for HTTP endpoints, and browser tests for critical flows.
MD);

    $indexer = new MemoryIndexer(new MemorySourceCatalog, new MemoryChunker);
    $indexer->index($agentId);
}

function makeEngine(): MemoryRetrievalEngine
{
    return new MemoryRetrievalEngine(new MemorySourceCatalog);
}

it('returns empty when no index exists', function (): void {
    $results = makeEngine()->search($this->agentId, 'anything');

    expect($results)->toBe([]);
});

it('finds relevant results for keyword query', function (): void {
    seedAndIndex($this->agentDir, $this->agentId);

    $results = makeEngine()->search($this->agentId, 'laravel configuration database');

    expect($results)->not->toBeEmpty()
        ->and($results[0]->heading)->toBe('Laravel Configuration')
        ->and($results[0]->score)->toBeGreaterThan(0)
        ->and($results[0]->basis)->toBe(MemoryRetrievalBasis::Keyword);
});

it('returns results sorted by score descending', function (): void {
    seedAndIndex($this->agentDir, $this->agentId);

    $results = makeEngine()->search($this->agentId, 'deployment production');

    if (count($results) >= 2) {
        expect($results[0]->score)->toBeGreaterThanOrEqual($results[1]->score);
    }

    expect($results)->not->toBeEmpty();
});

it('filters stopwords from query', function (): void {
    seedAndIndex($this->agentDir, $this->agentId);

    // "the" and "is" are stopwords — should not affect results
    $results = makeEngine()->search($this->agentId, 'the testing is important');

    expect($results)->not->toBeEmpty()
        ->and($results[0]->heading)->toBe('Testing Strategy');
});

it('returns empty for query of only stopwords', function (): void {
    seedAndIndex($this->agentDir, $this->agentId);

    $results = makeEngine()->search($this->agentId, 'the and is it');

    expect($results)->toBe([]);
});

it('returns empty for empty query', function (): void {
    seedAndIndex($this->agentDir, $this->agentId);

    $results = makeEngine()->search($this->agentId, '');

    expect($results)->toBe([]);
});

it('includes source path and snippet in results', function (): void {
    seedAndIndex($this->agentDir, $this->agentId);

    $results = makeEngine()->search($this->agentId, 'pest unit tests');

    expect($results)->not->toBeEmpty()
        ->and($results[0]->sourcePath)->toBe('MEMORY.md')
        ->and($results[0]->snippet)->not->toBeEmpty();
});

it('respects max results limit', function (): void {
    seedAndIndex($this->agentDir, $this->agentId);

    $results = makeEngine()->search($this->agentId, 'Laravel', maxResults: 1);

    expect(count($results))->toBeLessThanOrEqual(1);
});

it('normalizes scores to 0-1 range', function (): void {
    seedAndIndex($this->agentDir, $this->agentId);

    $results = makeEngine()->search($this->agentId, 'laravel');

    foreach ($results as $result) {
        expect($result->score)->toBeGreaterThanOrEqual(0.0)
            ->and($result->score)->toBeLessThanOrEqual(1.0);
    }
});
