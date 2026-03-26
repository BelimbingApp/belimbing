<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Tools;

use App\Base\AI\Enums\ToolCategory;
use App\Base\AI\Enums\ToolRiskClass;
use App\Base\AI\Tools\AbstractTool;
use App\Base\AI\Tools\Concerns\ProvidesToolMetadata;
use App\Base\AI\Tools\Schema\ToolSchemaBuilder;
use App\Base\AI\Tools\ToolArgumentException;
use App\Base\AI\Tools\ToolResult;

/**
 * File editing tool for coding agents.
 *
 * Provides structured file creation and modification with path safety
 * guardrails. Preferred over BashTool for file operations because it
 * gives cleaner audit trails, avoids shell-quoting issues, and
 * validates paths stay within the project root.
 *
 * Supports two operations:
 * - write: Create or overwrite a file with the given content
 * - append: Append content to an existing file
 *
 * Gated by `ai.tool_edit_file.execute` authz capability.
 */
class EditFileTool extends AbstractTool
{
    use ProvidesToolMetadata;

    private const MAX_CONTENT_LENGTH = 50000;

    private const VALID_OPERATIONS = ['write', 'append'];

    /**
     * Paths that must never be written to (relative to project root).
     *
     * @var list<string>
     */
    private const DENIED_PATHS = [
        '.env',
        '.env.local',
        '.env.production',
        '.env.testing',
    ];

    /**
     * Directory prefixes that must never be written to.
     *
     * @var list<string>
     */
    private const DENIED_PREFIXES = [
        'storage/framework/',
        'vendor/',
        'node_modules/',
    ];

    public function name(): string
    {
        return 'edit_file';
    }

    public function description(): string
    {
        return 'Create or modify a file within the BLB project. '
            .'Use operation "write" to create a new file or overwrite an existing one. '
            .'Use operation "append" to add content to the end of an existing file. '
            .'Provide the file_path relative to the project root. '
            .'Always include the complete file content for write operations.';
    }

    protected function schema(): ToolSchemaBuilder
    {
        return ToolSchemaBuilder::make()
            ->string(
                'file_path',
                'File path relative to the project root (e.g., "app/Models/Example.php").'
            )->required()
            ->string(
                'content',
                'The file content to write or append.'
            )->required()
            ->string(
                'operation',
                'The operation: "write" to create/overwrite, "append" to add to end.',
                enum: self::VALID_OPERATIONS,
            );
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
        return 'ai.tool_edit_file.execute';
    }

    protected function toolMetadata(): array
    {
        return [
            'displayName' => 'Edit File',
            'summary' => 'Create or modify files in the project.',
            'explanation' => 'Creates or modifies files within the BLB project root. '
                .'Validates that file paths stay within the project directory and blocks '
                .'writes to sensitive files (.env, vendor/, node_modules/).',
            'testExamples' => [
                [
                    'label' => 'Create a PHP class',
                    'input' => [
                        'file_path' => 'app/Modules/Example/Models/Example.php',
                        'content' => "<?php\n\nnamespace App\\Modules\\Example\\Models;\n\nuse Illuminate\\Database\\Eloquent\\Model;\n\nclass Example extends Model\n{\n}\n",
                        'operation' => 'write',
                    ],
                    'runnable' => false,
                ],
            ],
            'limits' => [
                'Cannot write outside the project root',
                'Cannot modify .env files, vendor/, or node_modules/',
                'Content limited to '.self::MAX_CONTENT_LENGTH.' characters',
            ],
        ];
    }

    protected function handle(array $arguments): ToolResult
    {
        $filePath = $this->requireString($arguments, 'file_path');
        $content = $this->requireString($arguments, 'content');
        $operation = $this->requireEnum($arguments, 'operation', self::VALID_OPERATIONS, 'write');

        if (mb_strlen($content) > self::MAX_CONTENT_LENGTH) {
            throw new ToolArgumentException(
                'Content exceeds maximum length of '.self::MAX_CONTENT_LENGTH.' characters.'
            );
        }

        $this->validatePath($filePath);

        $absolutePath = base_path($filePath);

        return match ($operation) {
            'append' => $this->appendToFile($absolutePath, $filePath, $content),
            default => $this->writeFile($absolutePath, $filePath, $content),
        };
    }

    /**
     * Validate the file path for safety.
     *
     * @throws ToolArgumentException When the path is unsafe
     */
    private function validatePath(string $filePath): void
    {
        if (str_contains($filePath, '..')) {
            throw new ToolArgumentException('Path traversal ("..") is not allowed.');
        }

        if (str_starts_with($filePath, '/')) {
            throw new ToolArgumentException('Absolute paths are not allowed. Use paths relative to the project root.');
        }

        foreach (self::DENIED_PATHS as $denied) {
            if ($filePath === $denied) {
                throw new ToolArgumentException("Writing to \"{$denied}\" is not allowed.");
            }
        }

        foreach (self::DENIED_PREFIXES as $prefix) {
            if (str_starts_with($filePath, $prefix)) {
                throw new ToolArgumentException("Writing to \"{$prefix}\" is not allowed.");
            }
        }
    }

    /**
     * Write (create or overwrite) a file.
     */
    private function writeFile(string $absolutePath, string $filePath, string $content): ToolResult
    {
        $directory = dirname($absolutePath);

        if (! $this->ensureDirectoryExists($directory)) {
            return ToolResult::error(
                "Failed to create the parent directory for \"{$filePath}\".",
                'directory_create_failed',
            );
        }

        if (is_dir($absolutePath)) {
            return ToolResult::error(
                "Failed to write \"{$filePath}\" because the target path is a directory.",
                'file_write_failed',
            );
        }

        $existed = file_exists($absolutePath);
        $bytesWritten = file_put_contents($absolutePath, $content);

        if ($bytesWritten === false) {
            return ToolResult::error(
                "Failed to write \"{$filePath}\".",
                'file_write_failed',
            );
        }

        $verb = $existed ? 'Updated' : 'Created';

        return ToolResult::success("{$verb} {$filePath} ({$bytesWritten} bytes).");
    }

    /**
     * Append content to an existing file.
     */
    private function appendToFile(string $absolutePath, string $filePath, string $content): ToolResult
    {
        if (! file_exists($absolutePath)) {
            throw new ToolArgumentException(
                "File \"{$filePath}\" does not exist. Use operation \"write\" to create it."
            );
        }

        if (is_dir($absolutePath)) {
            return ToolResult::error(
                "Failed to append to \"{$filePath}\" because the target path is a directory.",
                'file_append_failed',
            );
        }

        $bytesWritten = file_put_contents($absolutePath, $content, FILE_APPEND);

        if ($bytesWritten === false) {
            return ToolResult::error(
                "Failed to append to \"{$filePath}\".",
                'file_append_failed',
            );
        }

        return ToolResult::success("Appended {$bytesWritten} bytes to {$filePath}.");
    }

    private function ensureDirectoryExists(string $directory): bool
    {
        if (is_dir($directory)) {
            return true;
        }

        if (! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            return false;
        }

        return true;
    }
}
