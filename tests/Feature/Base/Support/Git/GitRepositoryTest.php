<?php

use App\Base\Support\Git\GitRepository;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

const GIT_REPOSITORY_BUNDLE_PATH = '/srv/bundle';
const GIT_REPOSITORY_MIGRATION_A = 'ibp/Database/Migrations/a.php';
const GIT_REPOSITORY_MIGRATION_B = 'ibp/Database/Migrations/b.php';
const GIT_SAFE_DIRECTORY_OPTION = 'safe.directory=';

final class GitRepositoryLaunchException extends RuntimeException {}

function gitRepositoryCommand(string $path, string ...$args): array
{
    return ['git', '-c', GIT_SAFE_DIRECTORY_OPTION.str_replace('\\', '/', $path), ...$args];
}

test('commit stages and commits only the given paths, never a blanket add', function (): void {
    Process::fake();

    $result = (new GitRepository(GIT_REPOSITORY_BUNDLE_PATH))->commit(
        [GIT_REPOSITORY_MIGRATION_A, GIT_REPOSITORY_MIGRATION_B],
        'schema: graduate tables out of incubation',
    );

    expect($result->ok)->toBeTrue();

    Process::assertRan(fn ($p): bool => $p->command === gitRepositoryCommand(GIT_REPOSITORY_BUNDLE_PATH, 'add', '--', GIT_REPOSITORY_MIGRATION_A, GIT_REPOSITORY_MIGRATION_B));
    Process::assertRan(fn ($p): bool => $p->command === gitRepositoryCommand(GIT_REPOSITORY_BUNDLE_PATH, 'commit', '-m', 'schema: graduate tables out of incubation', '--', GIT_REPOSITORY_MIGRATION_A, GIT_REPOSITORY_MIGRATION_B));
    Process::assertDidntRun(fn ($p): bool => in_array('-A', $p->command, true) || in_array('.', $p->command, true));
});

test('commit with no paths is a skipped no-op', function (): void {
    Process::fake();

    $result = (new GitRepository(GIT_REPOSITORY_BUNDLE_PATH))->commit([], 'nothing to do');

    expect($result->ok)->toBeTrue();
    Process::assertNothingRan();
});

test('commit reports the failure when staging fails', function (): void {
    Process::fake(fn ($p) => in_array('add', $p->command, true)
        ? Process::result(errorOutput: "fatal: pathspec 'x' did not match any files", exitCode: 128)
        : Process::result());

    $result = (new GitRepository(GIT_REPOSITORY_BUNDLE_PATH))->commit(['x'], 'msg');

    expect($result->ok)->toBeFalse()
        ->and($result->message())->toContain('did not match any files');
    Process::assertDidntRun(fn ($p): bool => in_array('commit', $p->command, true));
});

test('repository detection accepts worktree metadata files', function (): void {
    $root = storage_path('framework/testing/git-repository-worktree');

    File::deleteDirectory($root);
    File::ensureDirectoryExists($root);
    file_put_contents($root.DIRECTORY_SEPARATOR.'.git', 'gitdir: C:/Repo/BelimbingApp/.git/worktrees/production');

    try {
        expect((new GitRepository($root))->isRepository())->toBeTrue();
    } finally {
        File::deleteDirectory($root);
    }
});

test('configured remote url reads git config without launching git', function (): void {
    $root = storage_path('framework/testing/git-repository-config');

    File::deleteDirectory($root);
    File::ensureDirectoryExists($root.DIRECTORY_SEPARATOR.'.git');
    file_put_contents($root.DIRECTORY_SEPARATOR.'.git'.DIRECTORY_SEPARATOR.'config', <<<'CONFIG'
[core]
    repositoryformatversion = 0
[remote "origin"]
    url = https://github.com/BelimbingApp/belimbing.git
    fetch = +refs/heads/*:refs/remotes/origin/*
CONFIG);
    Process::fake();

    try {
        expect((new GitRepository($root))->configuredRemoteUrl())->toBe('https://github.com/BelimbingApp/belimbing.git');
        Process::assertNothingRan();
    } finally {
        File::deleteDirectory($root);
    }
});

test('configured remote url follows worktree common git directory', function (): void {
    $root = storage_path('framework/testing/git-repository-worktree-config');
    $gitRoot = storage_path('framework/testing/git-repository-common');
    $worktreeGit = $gitRoot.DIRECTORY_SEPARATOR.'.git'.DIRECTORY_SEPARATOR.'worktrees'.DIRECTORY_SEPARATOR.'production';
    $commonGit = $gitRoot.DIRECTORY_SEPARATOR.'.git';

    File::deleteDirectory($root);
    File::deleteDirectory($gitRoot);
    File::ensureDirectoryExists($root);
    File::ensureDirectoryExists($worktreeGit);
    file_put_contents($root.DIRECTORY_SEPARATOR.'.git', 'gitdir: '.$worktreeGit);
    file_put_contents($worktreeGit.DIRECTORY_SEPARATOR.'commondir', '..'.DIRECTORY_SEPARATOR.'..');
    file_put_contents($commonGit.DIRECTORY_SEPARATOR.'config', <<<'CONFIG'
[remote "origin"]
    url = git@github.com:BelimbingApp/belimbing.git
CONFIG);
    Process::fake();

    try {
        expect((new GitRepository($root))->configuredRemoteUrl())->toBe('git@github.com:BelimbingApp/belimbing.git');
        Process::assertNothingRan();
    } finally {
        File::deleteDirectory($root);
        File::deleteDirectory($gitRoot);
    }
});

test('commands can use an explicit git executable', function (): void {
    Process::fake();

    (new GitRepository(GIT_REPOSITORY_BUNDLE_PATH, executable: '/opt/git/bin/git'))->remoteUrl();

    Process::assertRan(fn ($p): bool => $p->command === ['/opt/git/bin/git', '-c', GIT_SAFE_DIRECTORY_OPTION.GIT_REPOSITORY_BUNDLE_PATH, 'remote', 'get-url', 'origin']);
});

test('commands use the configured git executable', function (): void {
    $original = config('app.git_executable');

    config(['app.git_executable' => '/usr/local/bin/blb-git']);
    Process::fake();

    try {
        (new GitRepository(GIT_REPOSITORY_BUNDLE_PATH))->remoteUrl();

        Process::assertRan(fn ($p): bool => $p->command === ['/usr/local/bin/blb-git', '-c', GIT_SAFE_DIRECTORY_OPTION.GIT_REPOSITORY_BUNDLE_PATH, 'remote', 'get-url', 'origin']);
    } finally {
        config(['app.git_executable' => $original]);
    }
});

test('commands scope git safe-directory to the checkout path', function (): void {
    Process::fake();

    (new GitRepository('D:\\Repo\\BelimbingApp\\production'))->remoteUrl();

    Process::assertRan(fn ($p): bool => $p->command === [
        'git',
        '-c',
        'safe.directory=D:/Repo/BelimbingApp/production',
        'remote',
        'get-url',
        'origin',
    ]);
});

test('command launch failures are reported separately from git failures', function (): void {
    Process::fake(fn () => throw new GitRepositoryLaunchException('git executable was not found'));

    $result = (new GitRepository(GIT_REPOSITORY_BUNDLE_PATH))->remoteUrl();

    expect($result)->toBeNull();

    $failure = (new GitRepository(GIT_REPOSITORY_BUNDLE_PATH))->run(['remote', 'get-url', 'origin']);

    expect($failure->ok)->toBeFalse()
        ->and($failure->couldNotStart())->toBeTrue()
        ->and($failure->message())->toContain('Could not run git')
        ->and($failure->message())->toContain('git executable was not found');
});

test('aheadBehind parses the upstream left-right count', function (): void {
    Process::fake(fn ($p) => Process::result("4\t2"));

    expect((new GitRepository(GIT_REPOSITORY_BUNDLE_PATH))->aheadBehind())->toBe(['ahead' => 2, 'behind' => 4]);
});

test('aheadBehind is zero when there is no upstream', function (): void {
    Process::fake(fn ($p) => Process::result(errorOutput: 'fatal: no upstream configured', exitCode: 128));

    expect((new GitRepository(GIT_REPOSITORY_BUNDLE_PATH))->aheadBehind())->toBe(['ahead' => 0, 'behind' => 0]);
});

test('status summary reads branch drift and dirty entries from one status command', function (): void {
    Process::fake(fn ($p) => Process::result("## main...origin/main [ahead 2, behind 4]\n M a.php\n?? b.php\n D c.php"));

    expect((new GitRepository(GIT_REPOSITORY_BUNDLE_PATH))->statusSummary())->toBe([
        'branch' => 'main',
        'dirty' => 3,
        'ahead' => 2,
        'behind' => 4,
    ]);

    Process::assertRan(fn ($p): bool => $p->command === gitRepositoryCommand(GIT_REPOSITORY_BUNDLE_PATH, 'status', '--porcelain=v1', '--branch'));
});

test('status summary is null when git status fails', function (): void {
    Process::fake(fn ($p) => Process::result(errorOutput: 'fatal: not a git repository', exitCode: 128));

    expect((new GitRepository(GIT_REPOSITORY_BUNDLE_PATH))->statusSummary())->toBeNull();
});
