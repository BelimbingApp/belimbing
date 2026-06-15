<?php

use App\Base\Support\Git\GitRepository;
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

test('aheadBehind parses the upstream left-right count', function (): void {
    Process::fake(fn ($p) => Process::result("4\t2"));

    expect((new GitRepository('/srv/bundle'))->aheadBehind())->toBe(['ahead' => 2, 'behind' => 4]);
});

test('aheadBehind is zero when there is no upstream', function (): void {
    Process::fake(fn ($p) => Process::result(errorOutput: 'fatal: no upstream configured', exitCode: 128));

    expect((new GitRepository('/srv/bundle'))->aheadBehind())->toBe(['ahead' => 0, 'behind' => 0]);
});
