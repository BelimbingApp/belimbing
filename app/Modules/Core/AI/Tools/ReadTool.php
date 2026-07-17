<?php

namespace App\Modules\Core\AI\Tools;

use App\Base\AI\Contracts\ProvidesDisplaySummary;
use App\Base\AI\Enums\ToolCategory;
use App\Base\AI\Enums\ToolRiskClass;
use App\Base\AI\Tools\AbstractTool;
use App\Base\AI\Tools\Concerns\ProvidesToolMetadata;
use App\Base\AI\Tools\ToolResult;
use App\Modules\Core\AI\Services\RepositorySurfaceResolver;
use App\Modules\Core\AI\Tools\Concerns\BuildsSurfaceToolPayload;

class ReadTool extends AbstractTool implements ProvidesDisplaySummary
{
    use BuildsSurfaceToolPayload;
    use ProvidesToolMetadata;

    public function __construct(
        private readonly ?RepositorySurfaceResolver $surfaces = null,
    ) {}

    public function name(): string
    {
        return 'read';
    }

    public function description(): string
    {
        return 'Read repository files or database data. File reads are scoped to a selected repository surface and return bounded line chunks with next_offset continuation.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'target' => $this->repositoryTargetSchema('Read'),
                'file_path' => $this->repositoryFilePathSchema(),
                'target_surface' => $this->repositorySurfaceSchema('reads'),
                'query' => [
                    'type' => 'string',
                    'description' => 'Read-only SQL SELECT or WITH query when target is "data".',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum data rows when target is "data", or maximum file lines when target is "file".',
                    'minimum' => 1,
                    'maximum' => 100,
                ],
                'offset' => [
                    'type' => 'integer',
                    'description' => 'Zero-based line offset for file reads. Use next_offset from a previous file read to continue.',
                    'minimum' => 0,
                ],
            ],
        ];
    }

    public function category(): ToolCategory
    {
        return ToolCategory::DATA;
    }

    public function riskClass(): ToolRiskClass
    {
        return ToolRiskClass::READ_ONLY;
    }

    public function requiredCapability(): ?string
    {
        return 'admin.ai.tool.read.execute';
    }

    public function displaySummary(array $arguments): string
    {
        if (($arguments['target'] ?? 'file') === 'data') {
            $query = is_string($arguments['query'] ?? null) ? trim($arguments['query']) : '';

            return $query !== '' ? __('Query data: :query', ['query' => $query]) : __('Query data');
        }

        $path = is_string($arguments['file_path'] ?? null) ? $arguments['file_path'] : '';
        $offset = $arguments['offset'] ?? null;
        $suffix = is_numeric($offset) && (int) $offset > 0 ? ' @'.(int) $offset : '';

        return $path !== '' ? __('Read :path', ['path' => $path]).$suffix : __('Read file');
    }

    protected function toolMetadata(): array
    {
        return [
            'displayName' => 'Read',
            'summary' => 'Read repository files or database data.',
            'explanation' => 'Reads files from BLB core or extension surfaces and executes guarded read-only SQL queries. '
                .'File output is bounded and scoped to the selected repository surface. '
                .'This is the broad read capability for agents; narrower file and data readers are internal implementation details.',
            'testExamples' => [
                [
                    'label' => 'Read a file',
                    'input' => [
                        'target' => 'file',
                        'file_path' => 'README.md',
                        'target_surface' => 'core',
                    ],
                ],
                [
                    'label' => 'Read data',
                    'input' => [
                        'target' => 'data',
                        'query' => 'SELECT count(*) AS total FROM users',
                    ],
                    'runnable' => false,
                ],
            ],
            'limits' => [
                'File reads stay inside the selected repository surface',
                'Environment files, dependencies, logs, wire logs, and generated caches are blocked',
                'File reads return bounded line chunks with next_offset continuation',
                'Data reads accept SELECT and WITH statements only',
            ],
        ];
    }

    protected function handle(array $arguments): ToolResult
    {
        $target = $this->requireEnum($arguments, 'target', ['file', 'data'], 'file');

        if ($target === 'data') {
            $payload = [
                'query' => $this->requireString($arguments, 'query'),
                ...$this->copyPresentKeys($arguments, ['limit']),
            ];

            return (new QueryDataTool)->execute($payload);
        }

        return (new ReadFileTool($this->surfaces))->execute([
            ...$this->surfaceFilePayload($arguments),
            ...$this->copyPresentKeys($arguments, ['offset', 'limit']),
        ]);
    }
}
