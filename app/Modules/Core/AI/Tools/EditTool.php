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

class EditTool extends AbstractTool
{
    use ProvidesToolMetadata;

    public function __construct(
        private readonly ?RepositorySurfaceResolver $surfaces = null,
    ) {}

    public function name(): string
    {
        return 'edit';
    }

    public function description(): string
    {
        return 'Edit Belimbing files or data. Use target "file" to write, append, or replace repository file content, '
            .'or target "data" to run guarded SQL INSERT, UPDATE, or DELETE statements. File edits can target BLB core '
            .'or extension surfaces with target_surface.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'target' => [
                    'type' => 'string',
                    'description' => 'Edit target: "file" or "data". Defaults to "file".',
                    'enum' => ['file', 'data'],
                ],
                'file_path' => [
                    'type' => 'string',
                    'description' => 'Path relative to the selected target surface when target is "file".',
                ],
                'operation' => [
                    'type' => 'string',
                    'description' => 'File operation: "write", "append", or "replace". Defaults to "write".',
                    'enum' => ['write', 'append', 'replace'],
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'Content for file write or append operations.',
                ],
                'old_content' => [
                    'type' => 'string',
                    'description' => 'Exact existing file text to replace when operation is "replace".',
                ],
                'new_content' => [
                    'type' => 'string',
                    'description' => 'Replacement file text when operation is "replace".',
                ],
                'target_surface' => [
                    'type' => 'string',
                    'description' => 'Repository ownership surface for file edits: "core" or "extension:<slug>". Defaults to "core".',
                ],
                'statement' => [
                    'type' => 'string',
                    'description' => 'SQL INSERT, UPDATE, or DELETE statement when target is "data".',
                ],
            ],
        ];
    }

    public function category(): ToolCategory
    {
        return ToolCategory::SYSTEM;
    }

    public function riskClass(): ToolRiskClass
    {
        return ToolRiskClass::HIGH_IMPACT;
    }

    public function requiredCapability(): ?string
    {
        return 'ai.tool_edit.execute';
    }

    protected function toolMetadata(): array
    {
        return [
            'displayName' => 'Edit',
            'summary' => 'Edit repository files or database data.',
            'explanation' => 'Creates and modifies files in BLB core or extension surfaces, and runs guarded data writes. '
                .'This is the broad edit capability for agents; file and data editing are one user-authorized capability.',
            'testExamples' => [
                [
                    'label' => 'Create a file',
                    'input' => [
                        'target' => 'file',
                        'file_path' => 'tmp/example.txt',
                        'operation' => 'write',
                        'content' => 'Example',
                    ],
                    'runnable' => false,
                ],
                [
                    'label' => 'Update data',
                    'input' => [
                        'target' => 'data',
                        'statement' => 'UPDATE users SET updated_at = now() WHERE id = 1',
                    ],
                    'runnable' => false,
                ],
            ],
            'limits' => [
                'File edits stay inside the selected repository surface',
                'Environment files, dependencies, and generated caches are blocked',
                'Data edits accept INSERT, UPDATE, and DELETE only',
                'Protected system tables cannot be modified through data edits',
            ],
        ];
    }

    protected function handle(array $arguments): ToolResult
    {
        $target = $this->requireEnum($arguments, 'target', ['file', 'data'], 'file');

        if ($target === 'data') {
            return (new EditDataTool)->execute([
                'statement' => $this->requireString($arguments, 'statement'),
            ]);
        }

        $payload = [
            'file_path' => $this->requireString($arguments, 'file_path'),
            'operation' => $this->requireEnum($arguments, 'operation', ['write', 'append', 'replace'], 'write'),
            'target_surface' => $this->optionalString($arguments, 'target_surface') ?? 'core',
        ];

        foreach (['content', 'old_content', 'new_content'] as $key) {
            if (array_key_exists($key, $arguments)) {
                $payload[$key] = $arguments[$key];
            }
        }

        return (new EditFileTool($this->surfaces))->execute($payload);
    }
}
