<?php

namespace App\Core\AI\Tools;

use App\Core\AI\Services\RepositorySurfaceResolver;

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
        return 'Search repository paths or file contents within a selected repository surface.';
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
            'explanation' => 'Searches file paths or file contents inside the BLB platform or a selected domain or extension surface. '
                .'Output is bounded and scoped to the selected repository surface. '
                .'This is the broad search capability for agents; current implementation is repository search.',
            'limits' => [
                'Searches are scoped to the selected target surface',
                'Generated, dependency, and AI wire-log directories are excluded',
            ],
        ];
    }
}
