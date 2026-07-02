<?php

namespace App\Base\Foundation\Services;

use App\Base\Support\Git\GitRepository;

class NestedCheckoutGitState
{
    /**
     * @return array{hasGit: bool, dirty: bool, unpushed: int}
     */
    public function inspect(string $path): array
    {
        $repo = new GitRepository($path);

        if (! $repo->isRepository()) {
            return $this->presence($path);
        }

        $summary = $repo->statusSummary(timeout: 30);
        $unpushed = $repo->run(['rev-list', '--count', '--branches', '--not', '--remotes'], timeout: 30);

        return [
            'hasGit' => true,
            'dirty' => ($summary['dirty'] ?? 0) > 0,
            'unpushed' => $unpushed->ok ? (int) $unpushed->output : 0,
        ];
    }

    /**
     * @return array{hasGit: bool, dirty: bool, unpushed: int}
     */
    public function presence(string $path): array
    {
        return [
            'hasGit' => file_exists($path.DIRECTORY_SEPARATOR.'.git'),
            'dirty' => false,
            'unpushed' => 0,
        ];
    }
}
