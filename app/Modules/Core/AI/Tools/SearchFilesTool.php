<?php

namespace App\Modules\Core\AI\Tools;

use App\Base\AI\Contracts\ProvidesDisplaySummary;
use App\Base\AI\Enums\ToolCategory;
use App\Base\AI\Enums\ToolRiskClass;
use App\Base\AI\Tools\AbstractTool;
use App\Base\AI\Tools\Concerns\ProvidesToolMetadata;
use App\Base\AI\Tools\Schema\ToolSchemaBuilder;
use App\Base\AI\Tools\ToolResult;
use App\Modules\Core\AI\DTO\RepositorySurface;
use App\Modules\Core\AI\Services\RepositorySurfaceResolver;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

class SearchFilesTool extends AbstractTool implements ProvidesDisplaySummary
{
    use ProvidesToolMetadata;

    private const MAX_RESULTS = 100;

    private const MAX_FILE_BYTES = 1_000_000;

    public function __construct(
        private readonly ?RepositorySurfaceResolver $surfaces = null,
    ) {}

    public function name(): string
    {
        return 'search_files';
    }

    public function description(): string
    {
        return 'Search repository paths or file contents within a selected repository surface.';
    }

    protected function schema(): ToolSchemaBuilder
    {
        return ToolSchemaBuilder::make()
            ->string('query', 'Search query.')->required()
            ->string('mode', 'Search mode: "content" or "path". Defaults to "content".', enum: ['content', 'path'])
            ->string('target_surface', 'Repository ownership surface: "core" or "extension:<slug>". Defaults to "core".')
            ->integer('max_results', 'Maximum results to return.', min: 1, max: self::MAX_RESULTS);
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
        return 'admin.ai.tool.search-files.execute';
    }

    protected function toolMetadata(): array
    {
        return [
            'displayName' => 'Search Files',
            'summary' => 'Search repository files.',
            'explanation' => 'Searches file paths or file contents inside BLB core or a selected extension surface. '
                .'Output is bounded and scoped to the selected repository surface.',
            'limits' => [
                'Searches are scoped to the selected target surface',
                'Generated and dependency directories are excluded',
            ],
        ];
    }

    public function displaySummary(array $arguments): string
    {
        $query = is_string($arguments['query'] ?? null) ? $arguments['query'] : '';
        $mode = ($arguments['mode'] ?? 'content') === 'path' ? __('paths') : __('content');
        $surface = is_string($arguments['target_surface'] ?? null) && $arguments['target_surface'] !== 'core'
            ? ' · '.$arguments['target_surface']
            : '';

        return __('Search :mode for ":query"', ['mode' => $mode, 'query' => $query]).$surface;
    }

    protected function handle(array $arguments): ToolResult
    {
        $query = $this->requireString($arguments, 'query');
        $mode = $this->requireEnum($arguments, 'mode', ['content', 'path'], 'content');
        $targetSurface = $this->optionalString($arguments, 'target_surface') ?? 'core';
        $maxResults = $this->optionalInt($arguments, 'max_results', 50, 1, self::MAX_RESULTS);

        $surface = ($this->surfaces ?? new RepositorySurfaceResolver)->resolve($targetSurface);
        $lines = $this->search($surface, $query, $mode, $maxResults);

        return ToolResult::success(json_encode([
            'target_surface' => $surface->target,
            'mode' => $mode,
            'query' => $query,
            'count' => count($lines),
            'results' => $lines,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return list<string>
     */
    private function search(RepositorySurface $surface, string $query, string $mode, int $maxResults): array
    {
        $results = [];

        foreach ($this->files($surface) as $file) {
            $relativePath = $this->relativePath($surface, $file);

            if ($mode === 'path') {
                if (stripos($relativePath, $query) !== false) {
                    $results[] = $relativePath;
                }
            } else {
                array_push($results, ...$this->searchFileContents($file, $relativePath, $query, $maxResults - count($results)));
            }

            if (count($results) >= $maxResults) {
                break;
            }
        }

        return array_slice($results, 0, $maxResults);
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private function files(RepositorySurface $surface): iterable
    {
        return Finder::create()
            ->files()
            ->ignoreDotFiles(false)
            ->ignoreVCS(false)
            ->exclude(['.git', 'vendor', 'node_modules'])
            ->notPath('#^storage/app/ai/wire-logs(/|$)#')
            ->notPath('#^storage/(framework|logs)(/|$)#')
            ->notPath('#^bootstrap/cache(/|$)#')
            ->notName('.env')
            ->notName('.env.*')
            ->in($surface->rootPath)
            ->sortByName();
    }

    /**
     * @return list<string>
     */
    private function searchFileContents(SplFileInfo $file, string $relativePath, string $query, int $remaining): array
    {
        if ($remaining <= 0 || $file->getSize() > self::MAX_FILE_BYTES) {
            return [];
        }

        $contents = file_get_contents($file->getPathname());

        if ($contents === false || str_contains($contents, "\0")) {
            return [];
        }

        $matches = [];
        $lines = preg_split('/\R/', $contents);

        foreach ($lines === false ? [] : $lines as $index => $line) {
            if (stripos($line, $query) !== false) {
                $matches[] = sprintf('%s:%d:%s', $relativePath, $index + 1, trim($line));
            }

            if (count($matches) >= $remaining) {
                break;
            }
        }

        return $matches;
    }

    private function relativePath(RepositorySurface $surface, SplFileInfo $file): string
    {
        return str_replace('\\', '/', substr($file->getPathname(), strlen($surface->rootPath) + 1));
    }
}
