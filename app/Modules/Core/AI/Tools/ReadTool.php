<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Tools;

use App\Base\AI\Enums\ToolCategory;
use App\Base\AI\Enums\ToolRiskClass;
use App\Base\AI\Tools\AbstractTool;
use App\Base\AI\Tools\Concerns\ProvidesToolMetadata;
use App\Base\AI\Tools\ToolResult;
use App\Modules\Core\AI\Services\RepositorySurfaceResolver;

class ReadTool extends AbstractTool
{
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
        return 'Read Belimbing files or data. Use target "file" with file_path for repository files, '
            .'or target "data" with a read-only SQL query for database data. Files can target BLB core '
            .'or extension surfaces with target_surface.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'target' => [
                    'type' => 'string',
                    'description' => 'Read target: "file" or "data". Defaults to "file".',
                    'enum' => ['file', 'data'],
                ],
                'file_path' => [
                    'type' => 'string',
                    'description' => 'Path relative to the selected target surface when target is "file".',
                ],
                'target_surface' => [
                    'type' => 'string',
                    'description' => 'Repository ownership surface for file reads: "core" or "extension:<slug>". Defaults to "core".',
                ],
                'query' => [
                    'type' => 'string',
                    'description' => 'Read-only SQL SELECT or WITH query when target is "data".',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum data rows to return when target is "data".',
                    'minimum' => 1,
                    'maximum' => 100,
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
        return 'ai.tool_read.execute';
    }

    protected function toolMetadata(): array
    {
        return [
            'displayName' => 'Read',
            'summary' => 'Read repository files or database data.',
            'explanation' => 'Reads files from BLB core or extension surfaces and executes guarded read-only SQL queries. '
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
            ];

            if (array_key_exists('limit', $arguments)) {
                $payload['limit'] = $arguments['limit'];
            }

            return (new QueryDataTool)->execute($payload);
        }

        return (new ReadFileTool($this->surfaces))->execute([
            'file_path' => $this->requireString($arguments, 'file_path'),
            'target_surface' => $this->optionalString($arguments, 'target_surface') ?? 'core',
        ]);
    }
}
