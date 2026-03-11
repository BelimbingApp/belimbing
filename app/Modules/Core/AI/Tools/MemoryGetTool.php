<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Tools;

use App\Base\AI\Enums\ToolCategory;
use App\Base\AI\Enums\ToolRiskClass;
use App\Base\AI\Tools\AbstractTool;
use App\Base\AI\Tools\Schema\ToolSchemaBuilder;
use App\Base\AI\Tools\ToolArgumentException;
use App\Base\AI\Tools\ToolResult;
use App\Modules\Core\Employee\Models\Employee;

/**
 * Workspace and documentation file reading tool for Digital Workers.
 *
 * Allows a DW to read files from two scopes:
 * - `docs`: The BLB project documentation directory (base_path('docs/'))
 * - `workspace`: Lara's workspace directory (config('ai.workspace_path')/LARA_ID/)
 *
 * Safety: Path traversal is blocked, absolute paths are rejected, binary
 * files are detected, and output is capped at 500 lines.
 *
 * Gated by `ai.tool_memory_get.execute` authz capability.
 */
class MemoryGetTool extends AbstractTool
{
    private const MAX_LINES = 500;

    private const BINARY_CHECK_BYTES = 1024;

    public function name(): string
    {
        return 'memory_get';
    }

    public function description(): string
    {
        return 'Read a file from the project documentation or Lara\'s workspace. '
            .'Use scope "docs" to read architecture specs, blueprints, and guides (e.g., "architecture/database.md"). '
            .'Use scope "workspace" to read Lara\'s workspace files (e.g., "MEMORY.md", "notes/meeting-2026-03-06.md"). '
            .'Supports optional line range selection for large files.';
    }

    protected function schema(): ToolSchemaBuilder
    {
        return ToolSchemaBuilder::make()
            ->string(
                'path',
                'Relative file path within the chosen scope '
                    .'(e.g., "MEMORY.md", "architecture/database.md").',
            )->required()
            ->string(
                'scope',
                'Where to read from: "docs" for project documentation (default), '
                    .'"workspace" for Lara\'s workspace files.',
                enum: ['docs', 'workspace'],
            )
            ->integer('from', 'Start reading from this line number (1-indexed, default: 1).', min: 1)
            ->integer('lines', 'Maximum number of lines to return (default: all, capped at 500).', min: 1, max: self::MAX_LINES);
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
        return 'ai.tool_memory_get.execute';
    }

    protected function handle(array $arguments): ToolResult
    {
        $path = $this->requireString($arguments, 'path');

        $this->validatePath($path);

        $scope = $this->requireEnum($arguments, 'scope', ['docs', 'workspace'], 'docs');
        $from = $this->optionalInt($arguments, 'from', 1, min: 1);
        $maxLines = $this->optionalInt($arguments, 'lines', self::MAX_LINES, min: 1, max: self::MAX_LINES);
        $readResult = $this->readRequestedFile($path, $scope, $from, $maxLines);

        if (isset($readResult['error'])) {
            return ToolResult::error($readResult['error']);
        }

        return ToolResult::success($this->formatReadResult(
            path: $path,
            scope: $scope,
            from: $from,
            totalLines: $readResult['total_lines'],
            selectedLines: $readResult['selected_lines'],
        ));
    }

    /**
     * Validate the relative path for safety.
     *
     * Rejects absolute paths, directory traversal sequences, and null bytes.
     *
     * @param  string  $path  The relative path to validate
     *
     * @throws ToolArgumentException If the path is invalid
     */
    private function validatePath(string $path): void
    {
        if (str_starts_with($path, '/')) {
            throw new ToolArgumentException('Invalid path: absolute paths are not allowed.');
        }

        if (str_contains($path, '..')) {
            throw new ToolArgumentException('Invalid path: directory traversal is not allowed.');
        }

        if (str_contains($path, "\0")) {
            throw new ToolArgumentException('Invalid path: null bytes are not allowed.');
        }
    }

    /**
     * Resolve the base directory path for the given scope.
     *
     * @param  string  $scope  Either 'docs' or 'workspace'
     * @return string Absolute path to the scope's base directory
     */
    private function resolveBasePath(string $scope): string
    {
        if ($scope === 'workspace') {
            return config('ai.workspace_path').'/'.Employee::LARA_ID;
        }

        return base_path('docs');
    }

    /**
     * @return array{selected_lines?: list<string>, total_lines?: int, error?: string}
     */
    private function readRequestedFile(string $path, string $scope, int $from, int $maxLines): array
    {
        $basePath = $this->resolveBasePath($scope);
        $fullPath = $basePath.'/'.ltrim($path, '/');
        $realBase = realpath($basePath);
        $realFull = realpath($fullPath);

        if ($realBase === false) {
            return ['error' => 'Scope directory does not exist.'];
        }

        if ($realFull === false || ! is_file($realFull)) {
            return ['error' => 'File not found: '.$path];
        }

        if (! str_starts_with($realFull, $realBase.'/')) {
            return ['error' => 'Invalid path: directory traversal is not allowed.'];
        }

        if ($this->isBinary($realFull)) {
            return ['error' => 'Cannot read binary file: '.$path];
        }

        $allLines = file($realFull, FILE_IGNORE_NEW_LINES);

        if ($allLines === false) {
            return ['error' => 'Unable to read file: '.$path];
        }

        $totalLines = count($allLines);

        if ($from > $totalLines) {
            return ['error' => 'Start line '.$from.' exceeds file length ('.$totalLines.' lines).'];
        }

        /** @var list<string> $selectedLines */
        $selectedLines = array_slice($allLines, $from - 1, $maxLines);

        return [
            'selected_lines' => $selectedLines,
            'total_lines' => $totalLines,
        ];
    }

    /**
     * @param  list<string>  $selectedLines
     */
    private function formatReadResult(string $path, string $scope, int $from, int $totalLines, array $selectedLines): string
    {
        $content = implode("\n", $selectedLines);
        $returnedCount = count($selectedLines);
        $footer = $returnedCount.' lines';

        if ($from > 1 || $returnedCount < $totalLines) {
            $endLine = $from + $returnedCount - 1;
            $footer .= ' (lines '.$from.'-'.$endLine.' of '.$totalLines.')';
        }

        $footer .= ' from '.$scope.':'.$path;

        return '# '.basename($path)."\n\n".$content."\n\n---\n".$footer;
    }

    /**
     * Check whether the file appears to be binary by scanning for null bytes.
     *
     * @param  string  $filePath  Absolute path to the file
     */
    private function isBinary(string $filePath): bool
    {
        $handle = fopen($filePath, 'r');

        if ($handle === false) {
            return false;
        }

        $chunk = fread($handle, self::BINARY_CHECK_BYTES);
        fclose($handle);

        if ($chunk === false || $chunk === '') {
            return false;
        }

        return str_contains($chunk, "\0");
    }
}
