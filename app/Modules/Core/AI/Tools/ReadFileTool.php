<?php

namespace App\Modules\Core\AI\Tools;

use App\Base\AI\Enums\ToolCategory;
use App\Base\AI\Enums\ToolRiskClass;
use App\Base\AI\Tools\AbstractTool;
use App\Base\AI\Tools\Concerns\ProvidesToolMetadata;
use App\Base\AI\Tools\Schema\ToolSchemaBuilder;
use App\Base\AI\Tools\ToolArgumentException;
use App\Base\AI\Tools\ToolResult;
use App\Modules\Core\AI\Services\RepositorySurfaceResolver;
use SplFileObject;

class ReadFileTool extends AbstractTool
{
    use ProvidesToolMetadata;

    private const DEFAULT_LIMIT_LINES = 200;

    private const MAX_LIMIT_LINES = 500;

    private const MAX_RETURN_BYTES = 100000;

    public function __construct(
        private readonly ?RepositorySurfaceResolver $surfaces = null,
    ) {}

    public function name(): string
    {
        return 'read_file';
    }

    public function description(): string
    {
        return 'Read a repository file from a selected repository surface. Returns bounded line chunks with next_offset continuation.';
    }

    protected function schema(): ToolSchemaBuilder
    {
        return ToolSchemaBuilder::make()
            ->string('file_path', 'Path relative to the selected target surface.')->required()
            ->string('target_surface', 'Repository ownership surface: "core" or "extension:<slug>". Defaults to "core".')
            ->integer('offset', 'Zero-based line offset to start reading from. Defaults to 0.', min: 0)
            ->integer('limit', 'Maximum lines to return. Defaults to '.self::DEFAULT_LIMIT_LINES.'; maximum '.self::MAX_LIMIT_LINES.'.', min: 1, max: self::MAX_LIMIT_LINES);
    }

    public function category(): ToolCategory
    {
        return ToolCategory::SYSTEM;
    }

    public function riskClass(): ToolRiskClass
    {
        return ToolRiskClass::READ_ONLY;
    }

    public function requiredCapability(): ?string
    {
        return 'admin.ai.tool.read-file.execute';
    }

    protected function toolMetadata(): array
    {
        return [
            'displayName' => 'Read File',
            'summary' => 'Read repository files.',
            'explanation' => 'Reads files from BLB core or an extension surface with project-root path checks. '
                .'Output is bounded and scoped to the selected repository surface.',
            'limits' => [
                'Cannot read outside the selected surface',
                'Cannot read .env files, vendor/, node_modules/, or generated caches',
                'Returns at most '.self::MAX_LIMIT_LINES.' lines or '.self::MAX_RETURN_BYTES.' bytes per read',
                'Use next_offset as offset to continue when has_more is true',
            ],
        ];
    }

    protected function handle(array $arguments): ToolResult
    {
        $filePath = $this->requireString($arguments, 'file_path');
        $targetSurface = $this->optionalString($arguments, 'target_surface') ?? 'core';
        $offset = $this->optionalInt($arguments, 'offset', 0, 0);
        $limit = $this->optionalInt($arguments, 'limit', self::DEFAULT_LIMIT_LINES, 1, self::MAX_LIMIT_LINES);
        $this->validateDeniedPath($filePath);

        $resolver = $this->surfaces ?? new RepositorySurfaceResolver;
        $absolutePath = $resolver->absolutePath($filePath, $targetSurface);
        $displayPath = $resolver->displayPath($filePath, $targetSurface);

        if (! is_file($absolutePath)) {
            return ToolResult::error("File \"{$displayPath}\" was not found.", 'file_not_found');
        }

        try {
            $chunk = $this->readChunk($absolutePath, $offset, $limit);
        } catch (\Throwable) {
            return ToolResult::error("Failed to read \"{$displayPath}\".", 'file_read_failed');
        }

        return ToolResult::success(json_encode([
            'target_surface' => $targetSurface,
            'file_path' => $displayPath,
            'offset' => $offset,
            'limit' => $limit,
            'lines_returned' => $chunk['lines_returned'],
            'bytes' => strlen($chunk['content']),
            'has_more' => $chunk['has_more'],
            'next_offset' => $chunk['next_offset'],
            'content' => $chunk['content'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array{content: string, lines_returned: int, has_more: bool, next_offset: int}
     */
    private function readChunk(string $absolutePath, int $offset, int $limit): array
    {
        $file = new SplFileObject($absolutePath, 'r');
        $file->seek($offset);

        $content = '';
        $linesReturned = 0;

        while (! $file->eof() && $linesReturned < $limit) {
            $line = $file->fgets();

            if ($line === '' && $file->eof()) {
                break;
            }

            if (strlen($content) + strlen($line) > self::MAX_RETURN_BYTES) {
                break;
            }

            $content .= $line;
            $linesReturned++;
        }

        return [
            'content' => $content,
            'lines_returned' => $linesReturned,
            'has_more' => ! $file->eof(),
            'next_offset' => $offset + $linesReturned,
        ];
    }

    private function validateDeniedPath(string $filePath): void
    {
        $path = trim(str_replace('\\', '/', $filePath), '/');

        if (in_array($path, ['.env', '.env.local', '.env.production', '.env.testing'], true)) {
            throw new ToolArgumentException('Reading environment files is not allowed.');
        }

        foreach (['vendor/', 'node_modules/', 'storage/framework/', 'storage/logs/', 'storage/app/ai/wire-logs/'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                throw new ToolArgumentException("Reading \"{$prefix}\" is not allowed.");
            }
        }
    }
}
