<?php
namespace App\Modules\Core\AI\Tools;

use App\Modules\Core\AI\Services\RepositorySurfaceResolver;

class SearchTool extends SearchFilesTool
{
    public function __construct(
        ?RepositorySurfaceResolver $surfaces = null,
    ) {
        parent::__construct($surfaces);
    }

    public function name(): string
    {
        return 'search';
    }

    public function description(): string
    {
        return 'Search Belimbing repository paths or file contents in BLB core or an extension surface. '
            .'Excludes generated/dependency directories and secret files.';
    }

    public function requiredCapability(): ?string
    {
        return 'admin.ai.tool.search.execute';
    }

    protected function toolMetadata(): array
    {
        return [
            'displayName' => 'Search',
            'summary' => 'Search repository files.',
            'explanation' => 'Searches file paths or file contents inside BLB core or a selected extension surface. '
                .'This is the broad search capability for agents; current implementation is repository search.',
            'limits' => [
                'Searches are scoped to the selected target surface',
                'Generated, dependency, and AI wire-log directories are excluded',
            ],
        ];
    }
}
