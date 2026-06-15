<?php

use App\Base\Support\Git\GitRepository;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

const GIT_REPOSITORY_ROOT = '/srv/bundle';
const GIT_SAFE_DIRECTORY_OPTION = 'safe.directory=';
const GIT_FIRST_MIGRATION = 'ibp/Database/Migrations/a.php';
const GIT_SECOND_MIGRATION = 'ibp/Database/Migrations/b.php';

function gitRepositoryCommand(string $path, string ...$args): array
{
    return ['git', '-c', GIT_SAFE_DIRECTORY_OPTION.str_replace('\\', '/', $path), ...$args];
}

beforeEach(function (): void {
    config(['app.git_executable' => null]);
});

test('commit stages and commits only the given paths, never a blanket add', function (): void {
    Process::fake();

    $result = (new GitRepository(GIT_REPOSITORY_ROOT))->commit(
        [GIT_FIRST_MIGRATION, GIT_SECOND_MIGRATION],
        'schema: graduate tables out of incubation',
    );

    expect($result->ok)->toBeTrue();

    Process::assertRan(fn ($p): bool => $p->command === gitRepositoryCommand(GIT_REPOSITORY_ROOT, 'add', '--', GIT_FIRST_MIGRATION, GIT_SECOND_MIGRATION));
    Process::assertRan(fn ($p): bool => $p->command === gitRepositoryCommand(GIT_REPOSITORY_ROOT, 'commit', '-m', 'schema: graduate tables out of incubation', '--', GIT_FIRST_MIGRATION, GIT_SECOND_MIGRATION));
    Process::assertDidntRun(fn ($p): bool => in_array('-A', $p->command, true) || in_array('.', $p->command, true));
});

test('commit with no paths is a skipped no-op', function (): void {
    Process::fake();

    $result = (new GitRepository(GIT_REPOSITORY_ROOT))->commit([], 'nothing to do');

    expect($result->ok)->toBeTrue();
    Process::assertNothingRan();
});

test('commit reports the failure when staging fails', function (): void {
    Process::fake(fn ($p) => in_array('add', $p->command, true)
        ? Process::result(errorOutput: "fatal: pathspec 'x' did not match any files", exitCode: 128)
        : Process::result());

    $result = (new GitRepository(GIT_REPOSITORY_ROOT))->commit(['x'], 'msg');

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

test('commands can use an explicit git executable', function (): void {
    Process::fake();

    (new GitRepository(GIT_REPOSITORY_ROOT, executable: '/opt/git/bin/git'))->remoteUrl();

    Process::assertRan(fn ($p): bool => $p->command === ['/opt/git/bin/git', '-c', GIT_SAFE_DIRECTORY_OPTION.GIT_REPOSITORY_ROOT, 'remote', 'get-url', 'origin']);
});

test('commands use the configured git executable', function (): void {
    $original = config('app.git_executable');

    config(['app.git_executable' => '/usr/local/bin/blb-git']);
    Process::fake();

    try {
        (new GitRepository(GIT_REPOSITORY_ROOT))->remoteUrl();

        Process::assertRan(fn ($p): bool => $p->command === ['/usr/local/bin/blb-git', '-c', GIT_SAFE_DIRECTORY_OPTION.GIT_REPOSITORY_ROOT, 'remote', 'get-url', 'origin']);
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
        GIT_SAFE_DIRECTORY_OPTION.'D:/Repo/BelimbingApp/production',
        'remote',
        'get-url',
        'origin',
    ]);
});

test('command launch failures are reported separately from git failures', function (): void {
    Process::fake(fn () => throw new RuntimeException('git executable was not found'));

    $result = (new GitRepository(GIT_REPOSITORY_ROOT))->remoteUrl();

    expect($result)->toBeNull();

    $failure = (new GitRepository(GIT_REPOSITORY_ROOT))->run(['remote', 'get-url', 'origin']);

    expect($failure->ok)->toBeFalse()
        ->and($failure->couldNotStart())->toBeTrue()
        ->and($failure->message())->toContain('Could not run git')
        ->and($failure->message())->toContain('git executable was not found');
});

test('aheadBehind parses the upstream left-right count', function (): void {
    Process::fake(fn ($p) => Process::result("4\t2"));

    expect((new GitRepository(GIT_REPOSITORY_ROOT))->aheadBehind())->toBe(['ahead' => 2, 'behind' => 4]);
});

test('aheadBehind is zero when there is no upstream', function (): void {
    Process::fake(fn ($p) => Process::result(errorOutput: 'fatal: no upstream configured', exitCode: 128));

    expect((new GitRepository(GIT_REPOSITORY_ROOT))->aheadBehind())->toBe(['ahead' => 0, 'behind' => 0]);
});
