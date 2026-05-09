<?php
namespace App\Modules\Core\AI\Tools;

use App\Base\AI\Enums\ToolCategory;
use App\Base\AI\Enums\ToolRiskClass;
use App\Base\AI\Tools\AbstractTool;
use App\Base\AI\Tools\Concerns\ProvidesToolMetadata;
use App\Base\AI\Tools\ToolArgumentException;
use App\Base\AI\Tools\ToolResult;
use App\Modules\Core\AI\Services\RepositorySurfaceResolver;

/**
 * File editing tool for coding agents.
 *
 * Provides structured file creation and modification with path safety
 * guardrails. Preferred over BashTool for file operations because it
 * gives cleaner audit trails, avoids shell-quoting issues, and
 * validates paths stay within the project root.
 *
 * Supports three operations:
 * - write: Create or overwrite a file with the given content
 * - append: Append content to an existing file
 * - replace: Replace one exact text block with another
 *
 * Gated by `admin.ai.tool.edit-file.execute` authz capability.
 */
class EditFileTool extends AbstractTool
{
    use ProvidesToolMetadata;

    private const MAX_CONTENT_LENGTH = 50000;

    private const VALID_OPERATIONS = ['write', 'append', 'replace'];

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

    public function __construct(
        private readonly ?RepositorySurfaceResolver $surfaces = null,
    ) {}

    public function name(): string
    {
        return 'edit_file';
    }

    public function description(): string
    {
        return 'Create or modify a file within the Belimbing project. '
            .'Use operation "write" to create a new file or overwrite an existing one. '
            .'Use operation "append" to add content to the end of an existing file. '
            .'Use operation "replace" with old_content and new_content for targeted edits. '
            .'Provide the file_path relative to the project root. '
            .'Use target_surface "core" or "extension:<slug>" to enforce repository ownership.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'file_path' => [
                    'type' => 'string',
                    'description' => 'File path relative to the selected target surface.',
                ],
                'operation' => [
                    'type' => 'string',
                    'description' => 'Operation: "write", "append", or "replace". Defaults to "write".',
                    'enum' => self::VALID_OPERATIONS,
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'Content for write or append operations.',
                ],
                'old_content' => [
                    'type' => 'string',
                    'description' => 'Exact existing text to replace when operation is "replace".',
                ],
                'new_content' => [
                    'type' => 'string',
                    'description' => 'Replacement text when operation is "replace".',
                ],
                'target_surface' => [
                    'type' => 'string',
                    'description' => 'Repository ownership surface: "core" or "extension:<slug>". Defaults to "core".',
                ],
            ],
            'required' => ['file_path'],
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
        return 'admin.ai.tool.edit-file.execute';
    }

    protected function toolMetadata(): array
    {
        return [
            'displayName' => 'Edit File',
            'summary' => 'Create or modify files in the project.',
            'explanation' => 'Creates or modifies files within the Belimbing project root. '
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
        $inputPath = $this->requireString($arguments, 'file_path');
        $operation = $this->requireEnum($arguments, 'operation', self::VALID_OPERATIONS, 'write');
        $targetSurface = $this->optionalString($arguments, 'target_surface') ?? 'core';

        $resolver = $this->surfaces ?? new RepositorySurfaceResolver;
        $filePath = $resolver->resolvePath($inputPath, $targetSurface);
        $displayPath = $resolver->displayPath($inputPath, $targetSurface);

        $this->validatePath($filePath);

        $absolutePath = $resolver->absolutePath($inputPath, $targetSurface);

        return match ($operation) {
            'append' => $this->appendToFile(
                $absolutePath,
                $displayPath,
                $this->requireContent($arguments),
            ),
            'replace' => $this->replaceInFile($absolutePath, $displayPath, $arguments),
            default => $this->writeFile(
                $absolutePath,
                $displayPath,
                $this->requireContent($arguments),
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function requireContent(array $arguments): string
    {
        $content = $this->requireString($arguments, 'content');

        if (mb_strlen($content) > self::MAX_CONTENT_LENGTH) {
            throw new ToolArgumentException(
                'Content exceeds maximum length of '.self::MAX_CONTENT_LENGTH.' characters.'
            );
        }

        return $content;
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

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function replaceInFile(string $absolutePath, string $filePath, array $arguments): ToolResult
    {
        if (! file_exists($absolutePath)) {
            throw new ToolArgumentException("File \"{$filePath}\" does not exist.");
        }

        if (is_dir($absolutePath)) {
            return ToolResult::error(
                "Failed to edit \"{$filePath}\" because the target path is a directory.",
                'file_edit_failed',
            );
        }

        $oldContent = $this->requireString($arguments, 'old_content');
        $newContent = $this->requireString($arguments, 'new_content');

        if (mb_strlen($oldContent) + mb_strlen($newContent) > self::MAX_CONTENT_LENGTH) {
            throw new ToolArgumentException(
                'Replacement content exceeds maximum length of '.self::MAX_CONTENT_LENGTH.' characters.'
            );
        }

        $current = file_get_contents($absolutePath);
        if ($current === false) {
            return ToolResult::error("Failed to read \"{$filePath}\".", 'file_read_failed');
        }

        $count = substr_count($current, $oldContent);
        if ($count === 0) {
            return ToolResult::error("No exact match found in {$filePath}.", 'replace_not_found');
        }

        if ($count > 1) {
            return ToolResult::error(
                "Found {$count} matches in {$filePath}. Provide a more specific old_content block.",
                'replace_ambiguous',
            );
        }

        $updated = str_replace($oldContent, $newContent, $current);
        $bytesWritten = file_put_contents($absolutePath, $updated);

        if ($bytesWritten === false) {
            return ToolResult::error("Failed to update \"{$filePath}\".", 'file_write_failed');
        }

        return ToolResult::success("Updated {$filePath} with one targeted replacement ({$bytesWritten} bytes).");
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
