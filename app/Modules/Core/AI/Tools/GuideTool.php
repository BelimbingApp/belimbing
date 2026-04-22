<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Tools;

use App\Base\AI\Services\KnowledgeNavigator;
use App\Base\AI\Tools\AbstractReadOnlyMemoryTool;
use App\Base\AI\Tools\Schema\ToolSchemaBuilder;
use App\Base\AI\Tools\ToolResult;

/**
 * Belimbing framework documentation guide tool for Agents.
 *
 * Allows a agent to search curated Belimbing framework documentation by topic,
 * returning matched references with summaries and the content of the
 * top-matching file for substantive grounding.
 *
 * Gated by `ai.tool_guide.execute` authz capability.
 */
class GuideTool extends AbstractReadOnlyMemoryTool
{
    private const MAX_LINES = 200;

    private const MAX_SECTIONS_LIMIT = 10;

    public function __construct(
        private readonly KnowledgeNavigator $navigator,
    ) {}

    public function name(): string
    {
        return 'guide';
    }

    public function description(): string
    {
        return 'Search Belimbing framework documentation for a topic and return relevant references with summaries. '
            .'Use this to look up architecture decisions, module docs, conventions, or design patterns '
            .'before answering questions about the Belimbing framework.';
    }

    protected function schema(): ToolSchemaBuilder
    {
        return ToolSchemaBuilder::make()
            ->string('topic', 'Topic to search Belimbing framework documentation for.')->required()
            ->integer('max_sections', 'Maximum number of relevant sections to return (default 5, max 10).', min: 1, max: self::MAX_SECTIONS_LIMIT);
    }

    public function requiredCapability(): ?string
    {
        return 'ai.tool_guide.execute';
    }

    protected function metadata(): array
    {
        return [
            'display_name' => 'Guide',
            'summary' => 'Query Belimbing framework documentation for reference information.',
            'explanation' => 'Searches the Belimbing documentation directory for relevant sections on a given topic. '
                .'Returns curated reference material to help answer framework questions. '
                .'This tool reads documentation only — it cannot modify docs.',
            'setup_requirements' => [
                'Documentation directory must be present',
            ],
            'test_examples' => [
                [
                    'label' => 'Lookup topic',
                    'input' => ['topic' => 'authorization'],
                ],
            ],
            'health_checks' => [
                'Docs directory accessible',
            ],
            'limits' => [
                'Returns up to 5 sections by default',
                'Read-only access to docs/',
            ],
        ];
    }

    protected function handle(array $arguments): ToolResult
    {
        $topic = $this->requireString($arguments, 'topic');
        $maxSections = $this->optionalInt($arguments, 'max_sections', 5, min: 1, max: self::MAX_SECTIONS_LIMIT);

        $results = $this->navigator->search($topic, $maxSections);

        if ($results === []) {
            return ToolResult::success($this->formatNoResults($topic));
        }

        return ToolResult::success($this->formatResults($results, $topic));
    }

    /**
     * Format search results as a numbered reference list with top match content.
     *
     * @param  list<array{title: string, path: string, summary: string}>  $results
     */
    private function formatResults(array $results, string $topic): string
    {
        $count = count($results);
        $output = 'Found '.$count.' relevant reference'.($count !== 1 ? 's' : '').' for "'.$topic.'":'."\n";

        foreach ($results as $index => $ref) {
            $number = $index + 1;
            $output .= "\n".$number.'. **'.$ref['title'].'**'
                ."\n".'   Path: '.$ref['path']
                ."\n".'   '.$ref['summary']."\n";
        }

        $topMatchContent = $this->readFileContent($results[0]['path']);

        if ($topMatchContent !== null) {
            $output .= "\n".'---'
                ."\n".'Top match content ('.$results[0]['path'].'):'."\n\n"
                .$topMatchContent;
        }

        return $output;
    }

    private function formatNoResults(string $topic): string
    {
        $catalog = $this->navigator->catalog();

        $output = 'No documentation found for "'.$topic.'".'."\n\n".'Available topics:';

        foreach ($catalog as $entry) {
            $output .= "\n".'- '.$entry['title'].' ('.$entry['path'].')';
        }

        return $output;
    }

    /**
     * Read up to MAX_LINES lines from a documentation file.
     *
     * @param  string  $path  Relative path to the documentation file
     */
    private function readFileContent(string $path): ?string
    {
        $absolutePath = base_path($path);

        if (! file_exists($absolutePath)) {
            return null;
        }

        $lines = file($absolutePath, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            return null;
        }

        $lines = array_slice($lines, 0, self::MAX_LINES);

        return implode("\n", $lines);
    }
}
