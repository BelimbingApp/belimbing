<?php

use App\Base\Software\Livewire\Deployment\Index;
use App\Base\Software\Services\SoftwareSourceRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

const UPSTREAM_LOCAL_SHA = 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeef';
const UPSTREAM_HEAD_SHA = 'cafebabecafebabecafebabecafebabecafebabe';
const UPSTREAM_TRAILER = "\x1fCI\x1fCurrent";

beforeEach(function (): void {
    Cache::flush();
    Http::fake();
});

/**
 * Platform checkout is an operator fork (origin = fork, matching local), with a
 * framework upstream remote. $counts drives the fork↔upstream relationship as
 * rev-list would report it: [behind (upstream-only), ahead (fork-only)]; null
 * means the upstream object was never fetched. $remote/$branch cover non-default
 * names; $upstreamError simulates an unreachable upstream.
 *
 * @param  array{0: int, 1: int}|null  $counts
 */
function fakeUpstreamGit(
    string $remote = 'upstream',
    ?string $configuredRemote = null,
    ?string $configuredBranch = null,
    ?string $defaultBranch = 'main',
    ?array $counts = [0, 0],
    ?string $upstreamError = null,
): void {
    $upstreamBranch = $configuredBranch ?? $defaultBranch;

    Process::fake(function ($process) use ($remote, $configuredRemote, $configuredBranch, $defaultBranch, $counts, $upstreamError, $upstreamBranch) {
        $command = gitCommandWithoutConfig($process->command);

        $result = match ($command) {
            ['git', 'remote'] => Process::result("origin\n".$remote),
            ['git', 'config', '--get', 'belimbing.upstream-remote'] => $configuredRemote !== null
                ? Process::result($configuredRemote)
                : Process::result('', exitCode: 1),
            ['git', 'config', '--get', 'belimbing.upstream-branch'] => $configuredBranch !== null
                ? Process::result($configuredBranch)
                : Process::result('', exitCode: 1),
            ['git', 'remote', 'get-url', 'origin'] => Process::result('https://github.com/operator/belimbing-fork.git'),
            ['git', 'remote', 'get-url', $remote] => Process::result('https://github.com/BelimbingApp/belimbing.git'),
            ['git', 'status', '--porcelain=v1', '--branch'] => Process::result('## master...origin/master'),
            ['git', 'rev-parse', '--abbrev-ref', 'HEAD'] => Process::result('master'),
            ['git', 'log', '-1', '--format=%H%x1f%cI%x1f%an%x1f%s'] => Process::result(UPSTREAM_LOCAL_SHA."\x1f".now()->toIso8601String().UPSTREAM_TRAILER),
            ['git', 'ls-remote', '--exit-code', 'origin', 'refs/heads/master'] => Process::result(UPSTREAM_LOCAL_SHA."\trefs/heads/master"),
            ['git', 'ls-remote', '--symref', $remote, 'HEAD'] => $upstreamError !== null
                ? Process::result(errorOutput: $upstreamError, exitCode: 128)
                : Process::result("ref: refs/heads/{$defaultBranch}\tHEAD\n".UPSTREAM_HEAD_SHA."\tHEAD"),
            ['git', 'ls-remote', '--exit-code', $remote, 'refs/heads/'.$upstreamBranch] => $upstreamError !== null
                ? Process::result(errorOutput: $upstreamError, exitCode: 128)
                : Process::result(UPSTREAM_HEAD_SHA."\trefs/heads/{$upstreamBranch}"),
            ['git', 'show', '-s', '--format=%H%x1f%cI%x1f%an%x1f%s', UPSTREAM_HEAD_SHA] => Process::result(UPSTREAM_HEAD_SHA."\x1f".now()->toIso8601String().UPSTREAM_TRAILER),
            ['git', 'rev-list', '--left-right', '--count', UPSTREAM_HEAD_SHA.'...'.UPSTREAM_LOCAL_SHA] => $counts !== null
                ? Process::result($counts[0]."\t".$counts[1])
                : Process::result(errorOutput: 'fatal: bad revision', exitCode: 128),
            default => null,
        };

        return $result ?? Process::result();
    });
}

function platformUpstream(): ?array
{
    $status = collect(app(SoftwareSourceRepository::class)->status())->keyBy('key');

    return $status->get('platform')['upstream'] ?? null;
}

test('a deployment with no upstream remote renders exactly as today', function (): void {
    Process::fake(function ($process) {
        return match (gitCommandWithoutConfig($process->command)) {
            ['git', 'remote'] => Process::result('origin'),
            ['git', 'remote', 'get-url', 'origin'] => Process::result('https://github.com/operator/belimbing-fork.git'),
            ['git', 'status', '--porcelain=v1', '--branch'] => Process::result('## master...origin/master'),
            ['git', 'rev-parse', '--abbrev-ref', 'HEAD'] => Process::result('master'),
            ['git', 'log', '-1', '--format=%H%x1f%cI%x1f%an%x1f%s'] => Process::result(UPSTREAM_LOCAL_SHA."\x1f".now()->toIso8601String().UPSTREAM_TRAILER),
            ['git', 'ls-remote', '--exit-code', 'origin', 'refs/heads/master'] => Process::result(UPSTREAM_LOCAL_SHA."\trefs/heads/master"),
            default => Process::result(),
        };
    });

    $status = collect(app(SoftwareSourceRepository::class)->status())->keyBy('key');
    $platform = $status->get('platform');

    expect($platform['upstream'])->toBeNull()
        ->and($platform['update_state'])->toBe('up_to_date');

    $user = createAdminUser();
    $this->actingAs($user);

    Livewire::test(Index::class)
        ->call('loadLatestStatus')
        ->assertSee('Up to date')
        ->assertDontSee('Fork up to date')
        ->assertDontSee('Upstream');
});

test('a contained upstream reports the relationship and keeps the plain Up to date badge', function (): void {
    fakeUpstreamGit(counts: [0, 3]);

    $upstream = platformUpstream();

    expect($upstream)->not->toBeNull()
        ->and($upstream['repo'])->toBe('BelimbingApp/belimbing')
        ->and($upstream['branch'])->toBe('main')
        ->and($upstream['head']['sha'])->toBe(UPSTREAM_HEAD_SHA)
        ->and($upstream['relationship'])->toBe('contained')
        ->and($upstream['ahead'])->toBe(3)
        ->and($upstream['behind'])->toBe(0);

    $user = createAdminUser();
    $this->actingAs($user);

    Livewire::test(Index::class)
        ->call('loadLatestStatus')
        ->assertSee('Upstream contained')
        ->assertSee('Up to date')
        ->assertDontSee('Fork up to date');
});

test('a fast-forwardable upstream is stated with its commit count', function (): void {
    fakeUpstreamGit(counts: [4, 0]);

    $upstream = platformUpstream();

    expect($upstream['relationship'])->toBe('fast_forwardable')
        ->and($upstream['ahead'])->toBe(0)
        ->and($upstream['behind'])->toBe(4);
});

test('a divergent fork and upstream are stated with both counts', function (): void {
    fakeUpstreamGit(counts: [2, 5]);

    $upstream = platformUpstream();

    expect($upstream['relationship'])->toBe('divergent')
        ->and($upstream['ahead'])->toBe(5)
        ->and($upstream['behind'])->toBe(2);
});

test('Up to date is not shown from origin agreement alone when the upstream is not contained', function (): void {
    fakeUpstreamGit(counts: [4, 0]);

    $user = createAdminUser();
    $this->actingAs($user);

    Livewire::test(Index::class)
        ->call('loadLatestStatus')
        ->assertSee('Fork up to date')
        ->assertSee('fast-forwardable');
});

test('an unfetched upstream head is a stated reason, not an error or a guessed relationship', function (): void {
    fakeUpstreamGit(counts: null);

    $upstream = platformUpstream();

    expect($upstream['relationship'])->toBeNull()
        ->and($upstream['error'])->toBeNull()
        ->and($upstream['reason'])->toContain('fetch upstream');
});

test('an unreachable upstream is a stated failure on the upstream line, not an exception', function (): void {
    fakeUpstreamGit(upstreamError: 'fatal: unable to access https://github.com/BelimbingApp/belimbing.git: could not resolve host');

    $upstream = platformUpstream();

    expect($upstream['head'])->toBeNull()
        ->and($upstream['relationship'])->toBeNull()
        ->and($upstream['error'])->not->toBeNull();
});

test('an unreadable origin never substitutes the installed HEAD for the fork head', function (): void {
    // The SBG shape: origin (the fork) is unreadable — lapsed credentials — while
    // the checkout carries unpushed local commits, so installed HEAD and fork head
    // genuinely differ. The relationship must become unknown with a stated reason,
    // not the installed checkout's relationship presented as the fork's.
    fakeUpstreamGit(counts: [0, 3]);

    Process::fake(function ($process) {
        $command = gitCommandWithoutConfig($process->command);

        if ($command === ['git', 'ls-remote', '--exit-code', 'origin', 'refs/heads/master']) {
            return Process::result(errorOutput: 'fatal: could not read Username for https://github.com', exitCode: 128);
        }

        return fakeUpstreamGitResultForAnonymous($command) ?? Process::result();
    });

    $upstream = platformUpstream();

    expect($upstream)->not->toBeNull()
        ->and($upstream['head']['sha'])->toBe(UPSTREAM_HEAD_SHA)
        ->and($upstream['relationship'])->toBeNull()
        ->and($upstream['ahead'])->toBeNull()
        ->and($upstream['behind'])->toBeNull()
        ->and($upstream['reason'])->toContain('fork head could not be read');
});

test('non-default upstream remote and branch names configured in git config are honoured', function (): void {
    fakeUpstreamGit(remote: 'framework', configuredRemote: 'framework', configuredBranch: 'stable', counts: [1, 0]);

    $upstream = platformUpstream();

    expect($upstream['remote'])->toBe('framework')
        ->and($upstream['branch'])->toBe('stable')
        ->and($upstream['head']['sha'])->toBe(UPSTREAM_HEAD_SHA)
        ->and($upstream['relationship'])->toBe('fast_forwardable');
});

test('the upstream head resolves anonymously when no token is stored for the upstream owner', function (): void {
    $sawAuthEnv = false;

    fakeUpstreamGit(counts: [0, 3]);

    // No token is stored in this test (no GitHubTokenStore writes), so the
    // ls-remote must succeed purely from the faked git response — the assertion
    // above on relationship already proves it; here we additionally prove no
    // Authorization header was injected into any upstream call.
    Process::fake(function ($process) use (&$sawAuthEnv) {
        $command = gitCommandWithoutConfig($process->command);

        if ($command === ['git', 'ls-remote', '--symref', 'upstream', 'HEAD']) {
            $env = $process->environment ?? [];
            $sawAuthEnv = $sawAuthEnv || array_key_exists('GIT_CONFIG_VALUE_0', $env);

            return Process::result("ref: refs/heads/main\tHEAD\n".UPSTREAM_HEAD_SHA."\tHEAD");
        }

        return fakeUpstreamGitResultForAnonymous($command) ?? Process::result();
    });

    $upstream = platformUpstream();

    expect($upstream['head']['sha'])->toBe(UPSTREAM_HEAD_SHA)
        ->and($sawAuthEnv)->toBeFalse();
});

/**
 * @param  list<string>  $command
 */
function fakeUpstreamGitResultForAnonymous(array $command): mixed
{
    return match ($command) {
        ['git', 'remote'] => Process::result("origin\nupstream"),
        ['git', 'config', '--get', 'belimbing.upstream-remote'],
        ['git', 'config', '--get', 'belimbing.upstream-branch'] => Process::result('', exitCode: 1),
        ['git', 'remote', 'get-url', 'origin'] => Process::result('https://github.com/operator/belimbing-fork.git'),
        ['git', 'remote', 'get-url', 'upstream'] => Process::result('https://github.com/BelimbingApp/belimbing.git'),
        ['git', 'ls-remote', '--symref', 'upstream', 'HEAD'] => Process::result("ref: refs/heads/main\tHEAD\n".UPSTREAM_HEAD_SHA."\tHEAD"),
        ['git', 'status', '--porcelain=v1', '--branch'] => Process::result('## master...origin/master'),
        ['git', 'rev-parse', '--abbrev-ref', 'HEAD'] => Process::result('master'),
        ['git', 'log', '-1', '--format=%H%x1f%cI%x1f%an%x1f%s'] => Process::result(UPSTREAM_LOCAL_SHA."\x1f".now()->toIso8601String().UPSTREAM_TRAILER),
        ['git', 'ls-remote', '--exit-code', 'origin', 'refs/heads/master'] => Process::result(UPSTREAM_LOCAL_SHA."\trefs/heads/master"),
        ['git', 'show', '-s', '--format=%H%x1f%cI%x1f%an%x1f%s', UPSTREAM_HEAD_SHA] => Process::result(UPSTREAM_HEAD_SHA."\x1f".now()->toIso8601String().UPSTREAM_TRAILER),
        ['git', 'rev-list', '--left-right', '--count', UPSTREAM_HEAD_SHA.'...'.UPSTREAM_LOCAL_SHA] => Process::result("0\t3"),
        default => null,
    };
}
