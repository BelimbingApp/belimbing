<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Tools;

use App\Base\AI\Enums\ToolCategory;
use App\Base\AI\Enums\ToolRiskClass;
use App\Base\AI\Tools\AbstractTool;
use App\Base\AI\Tools\Concerns\ProvidesToolMetadata;
use App\Base\AI\Tools\Schema\ToolSchemaBuilder;
use App\Base\AI\Tools\ToolResult;
use App\Base\Support\Str as BlbStr;
use App\Modules\Core\AI\DTO\MemorySearchResult;
use App\Modules\Core\AI\Services\Memory\MemoryRetrievalEngine;
use App\Modules\Core\Employee\Models\Employee;

/**
 * Thin wrapper around the memory retrieval engine for agent tool use.
 *
 * Delegates all search logic to MemoryRetrievalEngine. Also searches
 * the project `docs/` directory as a reference corpus when the agent
 * has no indexed memory yet.
 *
 * Gated by `ai.tool_memory_search.execute` authz capability.
 */
class MemorySearchTool extends AbstractTool
{
    use ProvidesToolMetadata;

    private const MAX_RESULTS_LIMIT = 50;

    private const DEFAULT_MAX_RESULTS = 10;

    private const MAX_FILE_CHARS = 5000;

    private const PREVIEW_LENGTH = 200;

    private const HEADING_WEIGHT = 3;

    private const BODY_WEIGHT = 1;

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

    private ?MemoryRetrievalEngine $retrievalEngine = null;

    /**
     * Create an instance if the docs directory exists.
     *
     * Returns null when the docs directory is missing, allowing the
     * registry to skip registration of this tool.
     */
    public static function createIfAvailable(): ?self
    {
        $docsPath = base_path('docs');

        if (! is_dir($docsPath)) {
            return null;
        }

        return new self;
    }

    /**
     * Inject the retrieval engine for indexed memory search.
     *
     * Called by ServiceProvider after construction. When set, the tool
     * delegates to the engine for per-agent memory before falling back
     * to the docs corpus scan.
     */
    public function setRetrievalEngine(MemoryRetrievalEngine $engine): void
    {
        $this->retrievalEngine = $engine;
    }

    public function name(): string
    {
        return 'memory_search';
    }

    public function description(): string
    {
        return 'Search agent memory and project documentation by keyword. '
            .'Returns matched sections ranked by relevance with citations. '
            .'Searches indexed memory files first, then project docs as reference.';
    }

    protected function schema(): ToolSchemaBuilder
    {
        return ToolSchemaBuilder::make()
            ->string('query', 'Search text to find in memory and documentation.')->required()
            ->integer(
                'max_results',
                'Maximum number of results to return (default '
                    .self::DEFAULT_MAX_RESULTS.', max '.self::MAX_RESULTS_LIMIT.').',
                min: 1,
                max: self::MAX_RESULTS_LIMIT,
            );
    }

    public function category(): ToolCategory
    {
        return ToolCategory::MEMORY;
    }

    public function riskClass(): ToolRiskClass
    {
        return ToolRiskClass::READ_ONLY;
    }

    public function requiredCapability(): ?string
    {
        return 'ai.tool_memory_search.execute';
    }

    protected function toolMetadata(): array
    {
        return [
            'displayName' => 'Memory Search',
            'summary' => 'Search across agent memory and workspace knowledge.',
            'explanation' => 'Performs hybrid keyword search over indexed agent memory files '
                .'and project documentation. Returns citations with source paths, '
                .'headings, and relevance scores.',
            'setupRequirements' => [
                'Docs directory must exist',
                'Memory index built via blb:ai:memory:index',
            ],
            'testExamples' => [
                [
                    'label' => 'Search for topic',
                    'input' => ['query' => 'authorization capabilities'],
                ],
            ],
            'healthChecks' => [
                'Docs directory accessible',
                'Memory index up to date',
            ],
            'limits' => [
                'Searches memory and docs files only',
                'Maximum 10 results by default',
            ],
        ];
    }

    protected function handle(array $arguments): ToolResult
    {
        $query = $this->requireString($arguments, 'query', 'search query');
        $maxResults = $this->optionalInt($arguments, 'max_results', self::DEFAULT_MAX_RESULTS, min: 1, max: self::MAX_RESULTS_LIMIT);

        // Search indexed memory first when engine is available
        $memoryResults = $this->searchIndexedMemory($query, $maxResults);

        // Search docs as reference corpus
        $docsResults = $this->searchDocs($query, $maxResults);

        $allResults = $this->mergeResults($memoryResults, $docsResults, $maxResults);

        if ($allResults === []) {
            return ToolResult::success('No matches found for "'.$query.'".');
        }

        return ToolResult::success($this->formatAllResults($allResults, $query));
    }

    /**
     * Search the indexed memory via retrieval engine.
     *
     * @return list<MemorySearchResult>
     */
    private function searchIndexedMemory(string $query, int $limit): array
    {
        if ($this->retrievalEngine === null) {
            return [];
        }

        // Resolve agent ID from execution context
        $employeeId = $this->resolveAgentId();

        if ($employeeId === null) {
            return [];
        }

        return $this->retrievalEngine->search($employeeId, $query, $limit);
    }

    /**
     * Resolve the current agent's employee ID.
     *
     * Falls back to LARA_ID for the primary chat agent context.
     */
    private function resolveAgentId(): ?int
    {
        return Employee::LARA_ID;
    }

    /**
     * Search project docs directory (legacy reference corpus).
     *
     * @return list<array{score: int, path: string, heading: string, preview: string, source_class: string}>
     */
    private function searchDocs(string $query, int $limit): array
    {
        $tokens = $this->tokenize($query);

        if ($tokens === []) {
            return [];
        }

        $scored = [];
        $docsPath = base_path('docs');

        if (is_dir($docsPath)) {
            $this->scoreDirectory($docsPath, $tokens, $scored, 'docs');
        }

        usort($scored, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, $limit);
    }

    /**
     * Merge memory results and docs results into a combined output.
     *
     * Memory results appear first (higher trust), followed by reference docs.
     *
     * @param  list<MemorySearchResult>  $memoryResults
     * @param  list<array{score: int, path: string, heading: string, preview: string, source_class: string}>  $docsResults
     * @return list<array{path: string, heading: string, preview: string, label: string}>
     */
    private function mergeResults(array $memoryResults, array $docsResults, int $limit): array
    {
        $merged = [];

        foreach ($memoryResults as $r) {
            $merged[] = [
                'path' => 'memory:'.$r->sourcePath,
                'heading' => $r->heading,
                'preview' => $r->snippet,
                'label' => '['.$r->sourceType->value.'] score:'.round($r->score, 2).' via:'.$r->basis->value,
            ];
        }

        foreach ($docsResults as $r) {
            $merged[] = [
                'path' => $r['path'],
                'heading' => $r['heading'],
                'preview' => $r['preview'],
                'label' => '[reference] score:'.$r['score'],
            ];
        }

        return array_slice($merged, 0, $limit);
    }

    /**
     * Format merged results for agent consumption.
     *
     * @param  list<array{path: string, heading: string, preview: string, label: string}>  $results
     */
    private function formatAllResults(array $results, string $query): string
    {
        $count = count($results);
        $output = 'Found '.$count.' match'.($count !== 1 ? 'es' : '').' for "'.$query.'":';

        foreach ($results as $index => $match) {
            $number = $index + 1;
            $output .= "\n\n".$number.'. '.$match['label'].' '.$match['path']
                ."\n".'   Section: '.$match['heading']
                ."\n".'   '.$match['preview'];
        }

        return $output;
    }

    /**
     * Score all markdown files in a directory and append matches.
     *
     * @param  string  $directory  Absolute path to scan
     * @param  list<string>  $tokens  Query tokens
     * @param  list<array{score: int, path: string, heading: string, preview: string, source_class: string}>  &$scored
     * @param  string  $scopePrefix  Scope label
     */
    private function scoreDirectory(string $directory, array $tokens, array &$scored, string $scopePrefix): void
    {
        $files = $this->findMarkdownFiles($directory);

        foreach ($files as $file) {
            $content = file_get_contents($file, false, null, 0, self::MAX_FILE_CHARS);

            if ($content === false || $content === '') {
                continue;
            }

            $relativePath = $scopePrefix.'/'.ltrim(
                str_replace($directory, '', $file),
                '/'
            );

            $sections = $this->splitSections($content, $file);

            foreach ($sections as $section) {
                $score = $this->scoreSection($section, $tokens);

                if ($score > 0) {
                    $preview = BlbStr::truncate(trim($section['body']), self::PREVIEW_LENGTH, '');

                    $scored[] = [
                        'score' => $score,
                        'path' => $relativePath,
                        'heading' => $section['heading'],
                        'preview' => $preview,
                        'source_class' => 'reference',
                    ];
                }
            }
        }
    }

    /**
     * Split query into lowercase tokens, filtering stopwords.
     *
     * @return list<string>
     */
    private function tokenize(string $query): array
    {
        $normalized = mb_strtolower(trim($query));
        $words = preg_split('/[^a-z0-9]+/', $normalized) ?: [];
        $words = array_filter($words, fn (string $word): bool => $word !== '');

        $filtered = array_filter(
            $words,
            fn (string $word): bool => ! in_array($word, self::STOPWORDS, true)
        );

        return array_values(array_unique($filtered));
    }

    /**
     * Split markdown content into sections by `## ` headings.
     *
     * @return list<array{heading: string, body: string}>
     */
    private function splitSections(string $content, string $filePath): array
    {
        $lines = explode("\n", $content);
        $sections = [];
        $currentHeading = pathinfo($filePath, PATHINFO_FILENAME);
        $currentBody = '';

        foreach ($lines as $line) {
            if (str_starts_with($line, '## ')) {
                if (trim($currentBody) !== '') {
                    $sections[] = [
                        'heading' => $currentHeading,
                        'body' => $currentBody,
                    ];
                }

                $currentHeading = BlbStr::afterPrefix($line, '## ');
                $currentBody = '';
            } else {
                $currentBody .= $line."\n";
            }
        }

        if (trim($currentBody) !== '') {
            $sections[] = [
                'heading' => $currentHeading,
                'body' => $currentBody,
            ];
        }

        return $sections;
    }

    /**
     * Score a section by keyword overlap.
     *
     * @param  array{heading: string, body: string}  $section
     * @param  list<string>  $tokens
     */
    private function scoreSection(array $section, array $tokens): int
    {
        $heading = mb_strtolower($section['heading']);
        $body = mb_strtolower($section['body']);
        $score = 0;

        foreach ($tokens as $token) {
            if (str_contains($heading, $token)) {
                $score += self::HEADING_WEIGHT;
            }

            if (str_contains($body, $token)) {
                $score += self::BODY_WEIGHT;
            }
        }

        return $score;
    }

    /**
     * Recursively find all markdown files in a directory.
     *
     * @return list<string>
     */
    private function findMarkdownFiles(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && mb_strtolower($file->getExtension()) === 'md') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
