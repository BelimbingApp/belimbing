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

class ReadFileTool extends AbstractTool
{
    use ProvidesToolMetadata;

    private const MAX_BYTES = 100000;

    public function __construct(
        private readonly ?RepositorySurfaceResolver $surfaces = null,
    ) {}

    public function name(): string
    {
        return 'read_file';
    }

    public function description(): string
    {
        return 'Read a file from the BLB core repository or a selected extension surface. '
            .'Use target_surface "core" or "extension:<slug>".';
    }

    protected function schema(): ToolSchemaBuilder
    {
        return ToolSchemaBuilder::make()
            ->string('file_path', 'Path relative to the selected target surface.')->required()
            ->string('target_surface', 'Repository ownership surface: "core" or "extension:<slug>". Defaults to "core".');
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
            'explanation' => 'Reads files from BLB core or an extension surface with project-root path checks.',
            'limits' => [
                'Cannot read outside the selected surface',
                'Cannot read .env files, vendor/, node_modules/, or generated caches',
                'Maximum '.self::MAX_BYTES.' bytes per read',
            ],
        ];
    }

    protected function handle(array $arguments): ToolResult
    {
        $filePath = $this->requireString($arguments, 'file_path');
        $targetSurface = $this->optionalString($arguments, 'target_surface') ?? 'core';
        $this->validateDeniedPath($filePath);

        $resolver = $this->surfaces ?? new RepositorySurfaceResolver;
        $absolutePath = $resolver->absolutePath($filePath, $targetSurface);
        $displayPath = $resolver->displayPath($filePath, $targetSurface);

        if (! is_file($absolutePath)) {
            return ToolResult::error("File \"{$displayPath}\" was not found.", 'file_not_found');
        }

        $size = filesize($absolutePath);
        if ($size !== false && $size > self::MAX_BYTES) {
            throw new ToolArgumentException("File \"{$displayPath}\" exceeds the ".self::MAX_BYTES.' byte read limit.');
        }

        $content = file_get_contents($absolutePath);
        if ($content === false) {
            return ToolResult::error("Failed to read \"{$displayPath}\".", 'file_read_failed');
        }

        return ToolResult::success(json_encode([
            'target_surface' => $targetSurface,
            'file_path' => $displayPath,
            'bytes' => strlen($content),
            'content' => $content,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
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
