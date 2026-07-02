<?php

use App\Base\Foundation\Services\NestedCheckoutGitState;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

test('nested checkout state counts local commits on any branch that are not on a remote', function (): void {
    $path = storage_path('framework/testing/nested-checkout-git-state');

    File::deleteDirectory($path);
    File::ensureDirectoryExists($path.DIRECTORY_SEPARATOR.'.git');

    Process::fake(function ($process) {
        return match (gitCommandWithoutConfig($process->command)) {
            ['git', 'status', '--porcelain=v1', '--branch'] => Process::result('## feature'),
            ['git', 'rev-list', '--count', '--branches', '--not', '--remotes'] => Process::result('3'),
            default => Process::result(),
        };
    });

    try {
        expect(app(NestedCheckoutGitState::class)->inspect($path))->toBe([
            'hasGit' => true,
            'dirty' => false,
            'unpushed' => 3,
        ]);
    } finally {
        File::deleteDirectory($path);
    }
});
