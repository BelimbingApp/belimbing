<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\Services\Memory\MemoryChunker;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    config()->set('ai.memory.max_chunk_chars', 200);
});

function makeChunker(): MemoryChunker
{
    return new MemoryChunker;
}

it('chunks a simple markdown file into a single chunk', function (): void {
    $content = 'Short content without headings.';
    $hash = hash('sha256', $content);

    $chunks = makeChunker()->chunk($content, 'MEMORY.md', $hash);

    expect($chunks)->toHaveCount(1)
        ->and($chunks[0]->heading)->toBe('MEMORY')
        ->and($chunks[0]->content)->toBe('Short content without headings.')
        ->and($chunks[0]->sourceRelativePath)->toBe('MEMORY.md')
        ->and($chunks[0]->sourceHash)->toBe($hash)
        ->and($chunks[0]->order)->toBe(0);
});

it('splits by heading boundaries', function (): void {
    $content = <<<'MD'
## First Section

Content of the first section.

## Second Section

Content of the second section.
MD;

    $chunks = makeChunker()->chunk($content, 'notes.md', 'abc123');

    expect($chunks)->toHaveCount(2)
        ->and($chunks[0]->heading)->toBe('First Section')
        ->and($chunks[0]->content)->toBe('Content of the first section.')
        ->and($chunks[1]->heading)->toBe('Second Section')
        ->and($chunks[1]->content)->toBe('Content of the second section.');
});

it('uses filename as heading for content before first heading', function (): void {
    $content = <<<'MD'
Some intro text before any heading.

## Real Section

Section content.
MD;

    $chunks = makeChunker()->chunk($content, 'memory/2026-07-20.md', 'hash');

    expect($chunks[0]->heading)->toBe('2026-07-20')
        ->and($chunks[0]->content)->toBe('Some intro text before any heading.')
        ->and($chunks[1]->heading)->toBe('Real Section');
});

it('splits oversized sections by paragraphs', function (): void {
    $paragraph1 = str_repeat('A', 80);
    $paragraph2 = str_repeat('B', 80);
    $paragraph3 = str_repeat('C', 80);

    $content = "## Big Section\n\n{$paragraph1}\n\n{$paragraph2}\n\n{$paragraph3}";

    $chunks = makeChunker()->chunk($content, 'doc.md', 'hash');

    // With max 200 chars, paragraphs need splitting
    expect(count($chunks))->toBeGreaterThanOrEqual(2);

    // Check part numbering on split chunks
    $partChunks = array_filter($chunks, fn ($c) => str_contains($c->heading, '(part'));
    expect($partChunks)->not->toBeEmpty();
});

it('preserves chunk ordering', function (): void {
    $content = "## A\n\nContent A\n\n## B\n\nContent B\n\n## C\n\nContent C";

    $chunks = makeChunker()->chunk($content, 'doc.md', 'hash');

    $orders = array_map(fn ($c) => $c->order, $chunks);
    $sorted = $orders;
    sort($sorted);

    expect($orders)->toBe($sorted)
        ->and($orders)->toBe(array_unique($orders));
});

it('generates unique fingerprints for different content', function (): void {
    $content = "## A\n\nContent one\n\n## B\n\nContent two";

    $chunks = makeChunker()->chunk($content, 'doc.md', 'hash');

    expect($chunks)->toHaveCount(2)
        ->and($chunks[0]->fingerprint)->not->toBe($chunks[1]->fingerprint);
});

it('returns empty for blank content', function (): void {
    $chunks = makeChunker()->chunk('', 'empty.md', 'hash');

    expect($chunks)->toBe([]);
});

it('handles h1 and h3 headings too', function (): void {
    $content = "# Top Level\n\nIntro\n\n### Sub Level\n\nDetails";

    $chunks = makeChunker()->chunk($content, 'doc.md', 'hash');

    expect($chunks)->toHaveCount(2)
        ->and($chunks[0]->heading)->toBe('Top Level')
        ->and($chunks[1]->heading)->toBe('Sub Level');
});
