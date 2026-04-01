<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\Memory;

use App\Modules\Core\AI\DTO\MemorySearchResult;
use App\Modules\Core\AI\Enums\MemoryRetrievalBasis;

/**
 * Hybrid memory retrieval engine.
 *
 * Merges keyword (BM25-style) and vector similarity (when available)
 * into a single ranked result set with citations and score metadata.
 *
 * The engine operates against the per-agent SQLite memory index.
 * If no index exists, returns empty results (tools surface this to agents).
 */
class MemoryRetrievalEngine
{
    /**
     * English stopwords filtered from search queries.
     *
     * @var list<string>
     */
    private const STOPWORDS = [
        'a', 'an', 'and', 'are', 'as', 'at', 'be', 'but', 'by', 'for',
        'from', 'has', 'have', 'how', 'i', 'if', 'in', 'is', 'it', 'its',
        'me', 'my', 'no', 'not', 'of', 'on', 'or', 'our', 'so', 'than',
        'that', 'the', 'then', 'they', 'this', 'to', 'up', 'us', 'was',
        'we', 'what', 'when', 'which', 'who', 'will', 'with', 'you',
    ];

    private const MAX_SNIPPET_LENGTH = 300;

    public function __construct(
        private readonly MemorySourceCatalog $catalog,
    ) {}

    /**
     * Search an agent's memory index.
     *
     * @param  int  $employeeId  Agent employee ID
     * @param  string  $query  Natural-language search query
     * @param  int|null  $maxResults  Override default max results
     * @return list<MemorySearchResult>
     */
    public function search(int $employeeId, string $query, ?int $maxResults = null): array
    {
        $store = MemoryIndexStore::forAgent($employeeId);

        if (! $store->exists()) {
            return [];
        }

        $store->ensureSchema();

        $limit = $maxResults ?? (int) config('ai.memory.default_max_results', 10);
        $tokens = $this->tokenize($query);

        if ($tokens === []) {
            return [];
        }

        $keywordResults = $store->keywordSearch($tokens, $limit);

        if ($keywordResults === []) {
            return [];
        }

        $maxScore = max(array_column($keywordResults, 'score'));

        $results = [];

        foreach ($keywordResults as $row) {
            $normalizedScore = $maxScore > 0 ? round($row['score'] / $maxScore, 4) : 0.0;
            $minThreshold = (float) config('ai.memory.min_score_threshold', 0.05);

            if ($normalizedScore < $minThreshold) {
                continue;
            }

            $results[] = new MemorySearchResult(
                sourcePath: $row['source_path'],
                heading: $row['heading'],
                snippet: $this->truncateSnippet($row['content']),
                score: $normalizedScore,
                basis: MemoryRetrievalBasis::Keyword,
                sourceType: $this->catalog->classifyPath($row['source_path']),
            );
        }

        return $results;
    }

    /**
     * Tokenize a query: lowercase, split on non-alphanumeric, remove stopwords.
     *
     * @return list<string>
     */
    private function tokenize(string $query): array
    {
        $normalized = mb_strtolower(trim($query));
        $words = preg_split('/[^a-z0-9]+/', $normalized) ?: [];
        $words = array_filter($words, fn (string $w): bool => $w !== '');

        $filtered = array_filter(
            $words,
            fn (string $w): bool => ! in_array($w, self::STOPWORDS, true),
        );

        return array_values(array_unique($filtered));
    }

    /**
     * Truncate content to a snippet length.
     */
    private function truncateSnippet(string $content): string
    {
        $content = trim($content);

        if (mb_strlen($content) <= self::MAX_SNIPPET_LENGTH) {
            return $content;
        }

        $truncated = mb_substr($content, 0, self::MAX_SNIPPET_LENGTH);

        // Break at last word boundary
        $lastSpace = mb_strrpos($truncated, ' ');

        if ($lastSpace !== false && $lastSpace > self::MAX_SNIPPET_LENGTH * 0.7) {
            $truncated = mb_substr($truncated, 0, $lastSpace);
        }

        return $truncated.'…';
    }
}
