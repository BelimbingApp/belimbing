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

class EditTool extends AbstractTool implements ProvidesDisplaySummary
{
    use BuildsSurfaceToolPayload;
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
        return 'Edit repository files or database data. File edits are scoped to a selected repository surface; data edits run guarded write statements.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'target' => $this->repositoryTargetSchema('Edit'),
                'file_path' => $this->repositoryFilePathSchema(),
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
                'target_surface' => $this->repositorySurfaceSchema('edits'),
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
        return 'admin.ai.tool.edit.execute';
    }

    public function displaySummary(array $arguments): string
    {
        if (($arguments['target'] ?? 'file') === 'data') {
            $statement = is_string($arguments['statement'] ?? null) ? trim($arguments['statement']) : '';

            return $statement !== '' ? __('Edit data: :statement', ['statement' => $statement]) : __('Edit data');
        }

        $path = is_string($arguments['file_path'] ?? null) ? $arguments['file_path'] : '';

        if ($path === '') {
            return __('Edit file');
        }

        return match ($arguments['operation'] ?? 'write') {
            'append' => __('Append to :path', ['path' => $path]),
            'replace' => __('Replace in :path', ['path' => $path]),
            default => __('Write :path', ['path' => $path]),
        };
    }

    protected function toolMetadata(): array
    {
        return [
            'displayName' => 'Edit',
            'summary' => 'Edit repository files or database data.',
            'explanation' => 'Creates and modifies files in BLB core or extension surfaces, and runs guarded data writes. '
                .'File changes are structured and auditable. '
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
            ...$this->surfaceFilePayload($arguments),
            'operation' => $this->requireEnum($arguments, 'operation', ['write', 'append', 'replace'], 'write'),
            ...$this->copyPresentKeys($arguments, ['content', 'old_content', 'new_content']),
        ];

        return (new EditFileTool($this->surfaces))->execute($payload);
    }
}
