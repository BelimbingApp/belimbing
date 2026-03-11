<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Tools;

use App\Base\AI\Enums\ToolCategory;
use App\Base\AI\Enums\ToolRiskClass;
use App\Base\AI\Services\KnowledgeNavigator;
use App\Base\AI\Tools\AbstractTool;
use App\Base\AI\Tools\Schema\ToolSchemaBuilder;
use App\Base\AI\Tools\ToolResult;

/**
 * BLB framework documentation guide tool for Digital Workers.
 *
 * Allows a DW to search curated BLB framework documentation by topic,
 * returning matched references with summaries and the content of the
 * top-matching file for substantive grounding.
 *
 * Gated by `ai.tool_guide.execute` authz capability.
 */
class GuideTool extends AbstractTool
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
        return 'Search BLB framework documentation for a topic and return relevant references with summaries. '
            .'Use this to look up architecture decisions, module docs, conventions, or design patterns '
            .'before answering questions about the BLB framework.';
    }

    protected function schema(): ToolSchemaBuilder
    {
        return ToolSchemaBuilder::make()
            ->string('topic', 'Topic to search BLB framework documentation for.')->required()
            ->integer('max_sections', 'Maximum number of relevant sections to return (default 5, max 10).', min: 1, max: self::MAX_SECTIONS_LIMIT);
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
        return 'ai.tool_guide.execute';
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
