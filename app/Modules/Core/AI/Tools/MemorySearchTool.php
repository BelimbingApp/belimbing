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
use App\Modules\Core\Employee\Models\Employee;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Semantic search tool for Agents over documentation and workspace files.
 *
 * Performs keyword-based search (BM25-style scoring) over indexed markdown
 * files from two sources:
 * - Project `docs/` directory (framework knowledge)
 * - agent workspace directory (if exists)
 *
 * Splits markdown files into sections by `##` headings and scores each
 * section by keyword overlap with the query. Heading matches are weighted
 * higher than body matches for relevance.
 *
 * Will be upgraded to full vector KNN search via sqlite-vec once embedding
 * infrastructure is ready.
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

    public function name(): string
    {
        return 'memory_search';
    }

    public function description(): string
    {
        return 'Search project documentation and workspace files by keyword. '
            .'Returns matched sections from markdown files ranked by relevance. '
            .'Use this to find specific topics, concepts, or references across '
            .'the entire documentation and workspace knowledge base.';
    }

    protected function schema(): ToolSchemaBuilder
    {
        return ToolSchemaBuilder::make()
            ->string('query', 'Search text to find in documentation and workspace files.')->required()
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
            'summary' => 'Search across workspace knowledge using semantic and keyword matching.',
            'explanation' => 'Performs hybrid vector + keyword search over markdown files in the agent workspace. '
                .'Requires embedding provider configuration and indexed workspace content. '
                .'This tool only reads indexed workspace files — it cannot access arbitrary files.',
            'setupRequirements' => [
                'Docs directory must exist',
                'Workspace content indexed',
            ],
            'testExamples' => [
                [
                    'label' => 'Search for topic',
                    'input' => ['query' => 'authorization capabilities'],
                ],
            ],
            'healthChecks' => [
                'Docs directory accessible',
                'Index up to date',
            ],
            'limits' => [
                'Searches workspace files only',
                'Maximum 10 results by default',
            ],
        ];
    }

    protected function handle(array $arguments): ToolResult
    {
        $query = $this->requireString($arguments, 'query', 'search query');
        $maxResults = $this->optionalInt($arguments, 'max_results', self::DEFAULT_MAX_RESULTS, min: 1, max: self::MAX_RESULTS_LIMIT);

        $matches = $this->searchFiles($query, $maxResults);

        if ($matches === []) {
            return ToolResult::success('No matches found for "'.$query.'".');
        }

        return ToolResult::success($this->formatResults($matches, $query));
    }

    /**
     * Search markdown files for sections matching the query.
     *
     * Scans both the project docs directory and the agent workspace directory,
     * scores each section by keyword overlap, and returns the top matches.
     *
     * @param  string  $query  Search text
     * @param  int  $limit  Maximum results to return
     * @return list<array{score: int, path: string, heading: string, preview: string}>
     */
    private function searchFiles(string $query, int $limit): array
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

        $workspacePath = $this->workspacePath();
        if ($workspacePath !== null && is_dir($workspacePath)) {
            $this->scoreDirectory($workspacePath, $tokens, $scored, 'workspace');
        }

        usort($scored, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, $limit);
    }

    /**
     * Score all markdown files in a directory and append matches.
     *
     * @param  string  $directory  Absolute path to scan
     * @param  list<string>  $tokens  Query tokens
     * @param  list<array{score: int, path: string, heading: string, preview: string}>  &$scored  Results accumulator
     * @param  string  $scopePrefix  Scope label ('docs' or 'workspace')
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
     * Each section has a heading and body. Content before the first heading
     * uses the filename as the heading.
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
     * Heading matches are weighted higher than body matches.
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
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && mb_strtolower($file->getExtension()) === 'md') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * Format search results as a numbered list with scores and previews.
     *
     * @param  list<array{score: int, path: string, heading: string, preview: string}>  $matches
     */
    private function formatResults(array $matches, string $query): string
    {
        $count = count($matches);
        $output = 'Found '.$count.' match'.($count !== 1 ? 'es' : '').' for "'.$query.'":';

        foreach ($matches as $index => $match) {
            $number = $index + 1;
            $output .= "\n\n".$number.'. ['.$match['score'].'] '.$match['path']
                ."\n".'   Section: '.$match['heading']
                ."\n".'   '.$match['preview'];
        }

        return $output;
    }

    /**
     * Resolve the agent workspace directory path.
     *
     * Returns null if the workspace path is not configured.
     */
    private function workspacePath(): ?string
    {
        $basePath = config('ai.workspace_path');

        if (! is_string($basePath) || $basePath === '') {
            return null;
        }

        return $basePath.'/'.Employee::LARA_ID;
    }
}
