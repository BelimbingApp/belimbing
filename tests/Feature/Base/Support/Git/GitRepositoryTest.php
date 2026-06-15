<?php

use App\Base\Support\Git\GitRepository;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

test('commit stages and commits only the given paths, never a blanket add', function (): void {
    Process::fake();

    $result = (new GitRepository('/srv/bundle'))->commit(
        ['ibp/Database/Migrations/a.php', 'ibp/Database/Migrations/b.php'],
        'schema: graduate tables out of incubation',
    );

    expect($result->ok)->toBeTrue();

    Process::assertRan(fn ($p): bool => $p->command === ['git', 'add', '--', 'ibp/Database/Migrations/a.php', 'ibp/Database/Migrations/b.php']);
    Process::assertRan(fn ($p): bool => $p->command === ['git', 'commit', '-m', 'schema: graduate tables out of incubation', '--', 'ibp/Database/Migrations/a.php', 'ibp/Database/Migrations/b.php']);
    Process::assertDidntRun(fn ($p): bool => in_array('-A', $p->command, true) || in_array('.', $p->command, true));
});

test('commit with no paths is a skipped no-op', function (): void {
    Process::fake();

    $result = (new GitRepository('/srv/bundle'))->commit([], 'nothing to do');

    expect($result->ok)->toBeTrue();
    Process::assertNothingRan();
});

test('commit reports the failure when staging fails', function (): void {
    Process::fake(fn ($p) => in_array('add', $p->command, true)
        ? Process::result(errorOutput: "fatal: pathspec 'x' did not match any files", exitCode: 128)
        : Process::result());

    $result = (new GitRepository('/srv/bundle'))->commit(['x'], 'msg');

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

    (new GitRepository('/srv/bundle', executable: '/opt/git/bin/git'))->remoteUrl();

    Process::assertRan(fn ($p): bool => $p->command === ['/opt/git/bin/git', 'remote', 'get-url', 'origin']);
});

test('commands use the configured git executable', function (): void {
    $original = config('app.git_executable');

    config(['app.git_executable' => '/usr/local/bin/blb-git']);
    Process::fake();

    try {
        (new GitRepository('/srv/bundle'))->remoteUrl();

        Process::assertRan(fn ($p): bool => $p->command === ['/usr/local/bin/blb-git', 'remote', 'get-url', 'origin']);
    } finally {
        config(['app.git_executable' => $original]);
    }
});

test('command launch failures are reported separately from git failures', function (): void {
    Process::fake(fn () => throw new RuntimeException('git executable was not found'));

    $result = (new GitRepository('/srv/bundle'))->remoteUrl();

    expect($result)->toBeNull();

    $failure = (new GitRepository('/srv/bundle'))->run(['remote', 'get-url', 'origin']);

    expect($failure->ok)->toBeFalse()
        ->and($failure->couldNotStart())->toBeTrue()
        ->and($failure->message())->toContain('Could not run git')
        ->and($failure->message())->toContain('git executable was not found');
});

test('aheadBehind parses the upstream left-right count', function (): void {
    Process::fake(fn ($p) => Process::result("4\t2"));

    expect((new GitRepository('/srv/bundle'))->aheadBehind())->toBe(['ahead' => 2, 'behind' => 4]);
});

test('aheadBehind is zero when there is no upstream', function (): void {
    Process::fake(fn ($p) => Process::result(errorOutput: 'fatal: no upstream configured', exitCode: 128));

    expect((new GitRepository('/srv/bundle'))->aheadBehind())->toBe(['ahead' => 0, 'behind' => 0]);
});
