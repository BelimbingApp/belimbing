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
            return ['hasGit' => false, 'dirty' => false, 'unpushed' => 0];
        }

        $status = $repo->run(['status', '--porcelain'], timeout: 30);
        $unpushed = $repo->run(['rev-list', '--count', '--branches', '--not', '--remotes'], timeout: 30);

        return [
            'hasGit' => true,
            'dirty' => $status->ok && $status->output !== '',
            'unpushed' => $unpushed->ok ? (int) $unpushed->output : 0,
        ];
    }
}
