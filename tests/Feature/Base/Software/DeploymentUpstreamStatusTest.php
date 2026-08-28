<?php

use App\Base\Settings\Contracts\SettingsService;
use App\Base\Software\Livewire\Deployment\Index;
use App\Base\Software\Services\SoftwareSourceRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

const UPSTREAM_LOCAL_SHA = 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeef';
const UPSTREAM_HEAD_SHA = 'cafebabecafebabecafebabecafebabecafebabe';
const UPSTREAM_MIRROR_SHA = 'a11ce000a11ce000a11ce000a11ce000a11ce000';
const UPSTREAM_TRAILER = "\x1fCI\x1fCurrent";

beforeEach(function (): void {
    Cache::flush();
    Http::fake();
});

/**
 * Platform checkout is an operator fork (origin = fork). The release flow's
 * two transitions (#374) are driven independently:
 *
 * - $mirror: 'absent' | 'current' (equals upstream head) | a SHA (distinct
 *   mirror head) | 'fail' (lookup error).
 * - $mirrorCounts: [upstream-only, mirror-only] from rev-list when the mirror
 *   SHA differs from upstream's; null = objects unfetched.
 * - $stableCounts: [mirror-only(missing), stable-only(fork own)] for the
 *   stable comparison; null = objects unfetched.
 * - $originError: origin (stable head) unreadable.
 * - $upstreamError: upstream head unreachable.
 */
function fakeUpstreamGit(
    string $remote = 'upstream',
    ?string $configuredRemote = null,
    ?string $configuredBranch = null,
    string $defaultBranch = 'main',
    string $mirror = 'current',
    ?array $mirrorCounts = null,
    ?array $stableCounts = [0, 0],
    ?string $originError = null,
    ?string $upstreamError = null,
    string $localBranch = 'master',
    ?string $localSha = null,
): void {
    $upstreamBranch = $configuredBranch ?? $defaultBranch;
    $localSha ??= UPSTREAM_LOCAL_SHA;
    $mirrorSha = match ($mirror) {
        'current' => UPSTREAM_HEAD_SHA,
        'absent', 'fail' => null,
        default => $mirror,
    };

    Process::fake(function ($process) use ($remote, $configuredRemote, $configuredBranch, $defaultBranch, $mirror, $mirrorSha, $mirrorCounts, $stableCounts, $originError, $upstreamError, $upstreamBranch, $localBranch, $localSha) {
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
            ['git', 'status', '--porcelain=v1', '--branch'] => Process::result("## {$localBranch}...origin/{$localBranch}"),
            ['git', 'rev-parse', '--abbrev-ref', 'HEAD'] => Process::result($localBranch),
            ['git', 'log', '-1', '--format=%H%x1f%cI%x1f%an%x1f%s'] => Process::result($localSha."\x1f".now()->toIso8601String().UPSTREAM_TRAILER),
            // The latest pipeline follows the local branch — a decoy when the
            // checkout is parked off master. Keyed only for non-master so it
            // can never shadow the originError-aware master arm below.
            ['git', 'ls-remote', '--exit-code', 'origin', 'refs/heads/'.($localBranch !== 'master' ? $localBranch : chr(0))] => Process::result($localSha."\trefs/heads/{$localBranch}"),
            // Stable head: origin/master, the branch the checkout tracks.
            ['git', 'ls-remote', '--exit-code', 'origin', 'refs/heads/master'] => $originError !== null
                ? Process::result(errorOutput: $originError, exitCode: 128)
                : Process::result(UPSTREAM_LOCAL_SHA."\trefs/heads/master"),
            // Mirror head: origin/<upstream branch>, tri-state.
            ['git', 'ls-remote', '--exit-code', 'origin', 'refs/heads/'.$upstreamBranch] => match ($mirror) {
                'absent' => Process::result('', exitCode: 2),
                'fail' => Process::result(errorOutput: 'fatal: unable to access: could not resolve host', exitCode: 128),
                default => Process::result($mirrorSha."\trefs/heads/{$upstreamBranch}"),
            },
            // Upstream head via --symref (no branch configured) or --exit-code.
            ['git', 'ls-remote', '--symref', $remote, 'HEAD'] => $upstreamError !== null
                ? Process::result(errorOutput: $upstreamError, exitCode: 128)
                : Process::result("ref: refs/heads/{$defaultBranch}\tHEAD\n".UPSTREAM_HEAD_SHA."\tHEAD"),
            ['git', 'ls-remote', '--exit-code', $remote, 'refs/heads/'.$upstreamBranch] => $upstreamError !== null
                ? Process::result(errorOutput: $upstreamError, exitCode: 128)
                : Process::result(UPSTREAM_HEAD_SHA."\trefs/heads/{$upstreamBranch}"),
            ['git', 'show', '-s', '--format=%H%x1f%cI%x1f%an%x1f%s', UPSTREAM_HEAD_SHA] => Process::result(UPSTREAM_HEAD_SHA."\x1f".now()->toIso8601String().UPSTREAM_TRAILER),
            // Mirror vs upstream counts: rev-list <upstream>...<mirror>.
            ['git', 'rev-list', '--left-right', '--count', UPSTREAM_HEAD_SHA.'...'.($mirrorSha ?? '')] => $mirrorCounts !== null
                ? Process::result($mirrorCounts[0]."\t".$mirrorCounts[1])
                : Process::result(errorOutput: 'fatal: bad revision', exitCode: 128),
            // Stable vs mirror counts: rev-list <mirror>...<stable>.
            ['git', 'rev-list', '--left-right', '--count', ($mirrorSha ?? '').'...'.UPSTREAM_LOCAL_SHA] => $stableCounts !== null
                ? Process::result($stableCounts[0]."\t".$stableCounts[1])
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
        ->assertDontSee('Mirror');
});

// ---- relationship 1: mirror vs upstream ----

test('a mirror matching the upstream head is current', function (): void {
    fakeUpstreamGit(mirror: 'current');

    $upstream = platformUpstream();

    expect($upstream['mirror']['state'])->toBe('current')
        ->and($upstream['mirror']['sha'])->toBe(UPSTREAM_HEAD_SHA)
        ->and($upstream['head']['sha'])->toBe(UPSTREAM_HEAD_SHA);
});

test('an absent mirror branch is an explicit missing state, not an error', function (): void {
    fakeUpstreamGit(mirror: 'absent');

    $upstream = platformUpstream();

    expect($upstream['mirror']['state'])->toBe('missing')
        ->and($upstream['error'])->toBeNull()
        ->and($upstream['stable']['state'])->toBe('unknown')
        ->and($upstream['stable']['reason'])->toContain('No mirror branch exists yet');
});

test('a mirror behind the upstream is fast-forwardable with the commit count', function (): void {
    fakeUpstreamGit(mirror: UPSTREAM_MIRROR_SHA, mirrorCounts: [4, 0], stableCounts: [0, 2]);

    $upstream = platformUpstream();

    expect($upstream['mirror']['state'])->toBe('behind')
        ->and($upstream['mirror']['behind'])->toBe(4)
        ->and($upstream['mirror']['ahead'])->toBe(0);
});

test('a mirror holding its own commits is diverged with both counts', function (): void {
    fakeUpstreamGit(mirror: UPSTREAM_MIRROR_SHA, mirrorCounts: [3, 2], stableCounts: [0, 2]);

    $upstream = platformUpstream();

    expect($upstream['mirror']['state'])->toBe('diverged')
        ->and($upstream['mirror']['behind'])->toBe(3)
        ->and($upstream['mirror']['ahead'])->toBe(2);
});

test('a failed mirror lookup is unknown with a reason, never treated as absent', function (): void {
    fakeUpstreamGit(mirror: 'fail');

    $upstream = platformUpstream();

    expect($upstream['mirror']['state'])->toBe('unknown')
        ->and($upstream['mirror']['reason'])->toContain('Could not determine')
        ->and($upstream['stable']['state'])->toBe('unknown');
});

test('unfetched mirror objects are unknown with a fetch reason', function (): void {
    fakeUpstreamGit(mirror: UPSTREAM_MIRROR_SHA, mirrorCounts: null, stableCounts: [0, 0]);

    $upstream = platformUpstream();

    expect($upstream['mirror']['state'])->toBe('unknown')
        ->and($upstream['mirror']['reason'])->toContain('fetch upstream');
});

test('an unreachable upstream leaves the mirror uncomparable with a stated reason', function (): void {
    fakeUpstreamGit(mirror: UPSTREAM_MIRROR_SHA, upstreamError: 'fatal: could not resolve host');

    $upstream = platformUpstream();

    expect($upstream['mirror']['state'])->toBe('unknown')
        ->and($upstream['mirror']['reason'])->toContain('upstream head could not be read')
        ->and($upstream['error'])->not->toBeNull();
});

// ---- relationship 2: stable vs mirror ----

test('a stable branch holding every mirror commit is contained', function (): void {
    fakeUpstreamGit(mirror: UPSTREAM_MIRROR_SHA, mirrorCounts: [4, 0], stableCounts: [0, 7]);

    $upstream = platformUpstream();

    expect($upstream['stable']['state'])->toBe('contained')
        ->and($upstream['stable']['missing'])->toBe(0)
        ->and($upstream['stable']['fork_own'])->toBe(7);
});

test('updates available is keyed on mirror commits the stable branch lacks', function (): void {
    fakeUpstreamGit(mirror: UPSTREAM_MIRROR_SHA, mirrorCounts: [0, 0], stableCounts: [5, 12]);

    $upstream = platformUpstream();

    expect($upstream['stable']['state'])->toBe('behind')
        ->and($upstream['stable']['missing'])->toBe(5)
        ->and($upstream['stable']['fork_own'])->toBe(12);
});

test('an unreadable origin leaves the stable relationship unknown, never substituting local HEAD', function (): void {
    fakeUpstreamGit(mirror: UPSTREAM_MIRROR_SHA, originError: 'fatal: could not read Username for https://github.com');

    $upstream = platformUpstream();

    expect($upstream['stable']['state'])->toBe('unknown')
        ->and($upstream['stable']['reason'])->toContain('stable head could not be read');
});

test('a stable head equal to the mirror is contained without a rev-list call', function (): void {
    fakeUpstreamGit(mirror: UPSTREAM_LOCAL_SHA, mirrorCounts: [1, 0], stableCounts: null);

    $upstream = platformUpstream();

    expect($upstream['stable']['state'])->toBe('contained')
        ->and($upstream['stable']['missing'])->toBe(0);
});

test('the stable comparison is pinned to origin/master even when the checkout sits on another branch', function (): void {
    // sol's P1 #1 on #395: the branch identity must be fixed, not read from
    // the local checkout. A deployment parked on a feature branch must still
    // compare origin/master to the mirror — never origin/<feature>, whose
    // head the fixture serves as a decoy through the latest pipeline.
    fakeUpstreamGit(mirror: 'current', stableCounts: [3, 9], localBranch: 'feature-x', localSha: 'feedfacefeedfacefeedfacefeedfacefeedface');

    $upstream = platformUpstream();

    expect($upstream['mirror']['state'])->toBe('current')
        ->and($upstream['stable']['state'])->toBe('behind')
        ->and($upstream['stable']['missing'])->toBe(3)
        ->and($upstream['stable']['fork_own'])->toBe(9);

    Process::assertRan(fn ($process): bool => gitCommandWithoutConfig($process->command) === ['git', 'ls-remote', '--exit-code', 'origin', 'refs/heads/master']);
});

test('origin lookups authenticate with the origin owner token so private forks resolve', function (): void {
    // sol's P1 #2 on #395: the mirror and stable probes go to origin, and a
    // private deployment fork must not read as Unknown because they went out
    // anonymously. The auth travels in env, never argv.
    app(SettingsService::class)->set('integrations.github.token.operator', 'ghp_upstream_status_token_000000000000');
    fakeUpstreamGit(mirror: 'current', stableCounts: [0, 0]);

    platformUpstream();

    Process::assertRan(function ($process): bool {
        if (gitCommandWithoutConfig($process->command) !== ['git', 'ls-remote', '--exit-code', 'origin', 'refs/heads/main']) {
            return false;
        }
        $environment = $process->environment ?? [];

        return ($environment['GIT_CONFIG_VALUE_0'] ?? '') === 'Authorization: Basic '.base64_encode('x-access-token:ghp_upstream_status_token_000000000000');
    });
});

// ---- configuration and page rendering ----

test('non-default upstream remote and branch names configured in git config are honoured', function (): void {
    fakeUpstreamGit(remote: 'framework', configuredRemote: 'framework', configuredBranch: 'stable', mirror: 'current');

    $upstream = platformUpstream();

    expect($upstream['remote'])->toBe('framework')
        ->and($upstream['branch'])->toBe('stable')
        ->and($upstream['mirror']['state'])->toBe('current');
});

test('the page shows both transitions and plain Up to date only when both are settled', function (): void {
    fakeUpstreamGit(mirror: 'current', stableCounts: [0, 3]);

    $user = createAdminUser();
    $this->actingAs($user);

    Livewire::test(Index::class)
        ->call('loadLatestStatus')
        ->assertSee('Mirror')
        ->assertSee('Current with the framework')
        ->assertSee('Stable')
        ->assertSee('Has every mirrored update')
        ->assertSee('Up to date')
        ->assertDontSee('Fork up to date');
});

test('pending updates suppress plain Up to date and name the release-candidate action', function (): void {
    fakeUpstreamGit(mirror: 'current', stableCounts: [5, 2]);

    $user = createAdminUser();
    $this->actingAs($user);

    Livewire::test(Index::class)
        ->call('loadLatestStatus')
        ->assertSee('updates available')
        ->assertSee('cut a release candidate')
        ->assertSee('Fork up to date')
        ->assertDontSeeHtml('>Up to date<');
});

test('a missing mirror renders as its own state on the page', function (): void {
    fakeUpstreamGit(mirror: 'absent');

    $user = createAdminUser();
    $this->actingAs($user);

    Livewire::test(Index::class)
        ->call('loadLatestStatus')
        ->assertSee('Not created yet')
        ->assertSee('No mirror branch exists yet');
});
