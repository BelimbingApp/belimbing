<?php

use App\Base\Software\Livewire\Deployment\Index;
use App\Base\Software\Services\GitHubTokenStore;
use App\Base\Software\Services\SoftwareSourceRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

const UPSTREAM_LOCAL_SHA = 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeef';
const UPSTREAM_HEAD_SHA = 'cafebabecafebabecafebabecafebabecafebabe';
const UPSTREAM_STABLE_SHA = 'a11ce000a11ce000a11ce000a11ce000a11ce000';
const UPSTREAM_TRAILER = "\x1fCI\x1fCurrent";

beforeEach(function (): void {
    Cache::flush();
    Http::fake();
});

/**
 * Platform checkout is an operator fork (origin = fork). The two fork lanes
 * (#482) are driven independently:
 *
 * - $checkoutCounts: [origin-only(to pull), local-only(unpushed)] from
 *   rev-list between the stable head and local HEAD; null = objects
 *   unfetched. Ignored when the two SHAs are equal.
 * - $stableCounts: [upstream-only(missing), stable-only(fork own)] for the
 *   stable comparison; null = objects unfetched.
 * - $originError: origin (stable head) unreadable — greys both origin lanes,
 *   never the upstream head.
 * - $upstreamError: upstream head unreachable — greys only the stable lane.
 */
function fakeUpstreamGit(
    string $remote = 'upstream',
    ?string $configuredRemote = null,
    ?string $configuredBranch = null,
    string $defaultBranch = 'main',
    ?array $checkoutCounts = [0, 0],
    ?array $stableCounts = [0, 0],
    ?string $originError = null,
    ?string $upstreamError = null,
    string $localBranch = 'master',
    ?string $localSha = null,
    ?string $stableSha = null,
): void {
    $upstreamBranch = $configuredBranch ?? $defaultBranch;
    $localSha ??= UPSTREAM_LOCAL_SHA;
    $stableSha ??= UPSTREAM_LOCAL_SHA;

    Process::fake(function ($process) use ($remote, $configuredRemote, $configuredBranch, $defaultBranch, $checkoutCounts, $stableCounts, $originError, $upstreamError, $upstreamBranch, $localBranch, $localSha, $stableSha) {
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
                : Process::result($stableSha."\trefs/heads/master"),
            // Upstream head via --symref (no branch configured) or --exit-code.
            ['git', 'ls-remote', '--symref', $remote, 'HEAD'] => $upstreamError !== null
                ? Process::result(errorOutput: $upstreamError, exitCode: 128)
                : Process::result("ref: refs/heads/{$defaultBranch}\tHEAD\n".UPSTREAM_HEAD_SHA."\tHEAD"),
            ['git', 'ls-remote', '--exit-code', $remote, 'refs/heads/'.$upstreamBranch] => $upstreamError !== null
                ? Process::result(errorOutput: $upstreamError, exitCode: 128)
                : Process::result(UPSTREAM_HEAD_SHA."\trefs/heads/{$upstreamBranch}"),
            ['git', 'show', '-s', '--format=%H%x1f%cI%x1f%an%x1f%s', UPSTREAM_HEAD_SHA] => Process::result(UPSTREAM_HEAD_SHA."\x1f".now()->toIso8601String().UPSTREAM_TRAILER),
            // Checkout lane: rev-list <stable>...<local HEAD> — `behind` is
            // what a pull brings, `ahead` this checkout's unpushed commits.
            ['git', 'rev-list', '--left-right', '--count', $stableSha.'...'.$localSha] => $checkoutCounts !== null
                ? Process::result($checkoutCounts[0]."\t".$checkoutCounts[1])
                : Process::result(errorOutput: 'fatal: bad revision', exitCode: 128),
            // Stable lane: rev-list <upstream>...<stable> — always the live
            // upstream head as the basis (#455/#458).
            ['git', 'rev-list', '--left-right', '--count', UPSTREAM_HEAD_SHA.'...'.$stableSha] => $stableCounts !== null
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
        $command = gitCommandWithoutConfig($process->command);

        return match ($command) {
            ['git', 'remote'] => Process::result('origin'),
            ['git', 'remote', 'get-url', 'origin'] => Process::result('https://github.com/BelimbingApp/belimbing.git'),
            ['git', 'status', '--porcelain=v1', '--branch'] => Process::result('## main...origin/main'),
            ['git', 'rev-parse', '--abbrev-ref', 'HEAD'] => Process::result('main'),
            ['git', 'log', '-1', '--format=%H%x1f%cI%x1f%an%x1f%s'] => Process::result(UPSTREAM_LOCAL_SHA."\x1f".now()->toIso8601String().UPSTREAM_TRAILER),
            ['git', 'ls-remote', '--exit-code', 'origin', 'refs/heads/main'] => Process::result(UPSTREAM_LOCAL_SHA."\trefs/heads/main"),
            default => Process::result(),
        };
    });

    expect(platformUpstream())->toBeNull();

    $this->actingAs(createAdminUser());
    Livewire::test(Index::class)
        ->call('loadLatestStatus')
        ->assertSee('Up to date')
        // The help panel names the lanes; their ROWS must not render for a
        // source with no upstream remote, asserted on the row questions.
        ->assertDontSee('Is this working copy current?')
        ->assertDontSee('Has stable integrated upstream?')
        ->assertDontSee('Upstream synchronization');
});

// ---------------------------------------------------------------------------
// Checkout lane: HEAD vs origin/master (#482).

test('a checkout whose HEAD equals the stable head is in sync without a rev-list call', function (): void {
    fakeUpstreamGit(checkoutCounts: null); // equal SHAs — counts must not be consulted

    $checkout = platformUpstream()['checkout'];

    expect($checkout['state'])->toBe('in_sync')
        ->and($checkout['ahead'])->toBe(0)
        ->and($checkout['behind'])->toBe(0);
});

test('checkout states follow the rev-list geometry', function (array $counts, string $state, int $behind, int $ahead): void {
    fakeUpstreamGit(checkoutCounts: $counts, stableSha: UPSTREAM_STABLE_SHA);

    $checkout = platformUpstream()['checkout'];

    expect($checkout['state'])->toBe($state)
        ->and($checkout['behind'])->toBe($behind)
        ->and($checkout['ahead'])->toBe($ahead);
})->with([
    'behind only — pull brings the fork current' => [[3, 0], 'behind', 3, 0],
    'ahead only — unpushed local commits' => [[0, 2], 'ahead', 0, 2],
    'diverged — both directions' => [[3, 2], 'diverged', 3, 2],
    'equal histories at distinct refs' => [[0, 0], 'in_sync', 0, 0],
]);

test('an unreadable origin leaves the checkout unknown with the stated reason', function (): void {
    fakeUpstreamGit(originError: 'fatal: could not read Username');

    $checkout = platformUpstream()['checkout'];

    expect($checkout['state'])->toBe('unknown')
        ->and($checkout['reason'])->toContain('stable head could not be read');
});

test('unfetched checkout objects are unknown with a fetch reason', function (): void {
    fakeUpstreamGit(checkoutCounts: null, stableSha: UPSTREAM_STABLE_SHA);

    $checkout = platformUpstream()['checkout'];

    expect($checkout['state'])->toBe('unknown')
        ->and($checkout['reason'])->toContain('fetch origin');
});

test('an unreachable upstream leaves the checkout lane fully readable', function (): void {
    // Lane isolation (#482): the checkout lane never consults upstream.
    fakeUpstreamGit(checkoutCounts: [3, 0], stableSha: UPSTREAM_STABLE_SHA, upstreamError: 'fatal: could not resolve host');

    $upstream = platformUpstream();

    expect($upstream['checkout']['state'])->toBe('behind')
        ->and($upstream['checkout']['behind'])->toBe(3)
        ->and($upstream['stable']['state'])->toBe('unknown');
});

// ---------------------------------------------------------------------------
// Fork stable lane: origin/master vs upstream/main.

test('a stable head equal to the live upstream head is contained without a rev-list call', function (): void {
    fakeUpstreamGit(stableCounts: null, stableSha: UPSTREAM_HEAD_SHA, localSha: UPSTREAM_HEAD_SHA);

    expect(platformUpstream()['stable']['state'])->toBe('contained');
});

test('stable states are keyed on upstream commits the stable branch lacks', function (array $counts, string $state, int $missing, int $forkOwn): void {
    fakeUpstreamGit(stableCounts: $counts, stableSha: UPSTREAM_STABLE_SHA, localSha: UPSTREAM_STABLE_SHA);

    $stable = platformUpstream()['stable'];

    expect($stable['state'])->toBe($state)
        ->and($stable['missing'])->toBe($missing)
        ->and($stable['fork_own'])->toBe($forkOwn);
})->with([
    'behind with fork-own commits' => [[5, 41], 'behind', 5, 41],
    'behind with none of its own' => [[2, 0], 'behind', 2, 0],
    'contained with fork-own commits (ahead is expected, not divergence)' => [[0, 41], 'contained', 0, 41],
]);

test('an unreadable origin leaves the stable relationship unknown, never substituting local HEAD', function (): void {
    fakeUpstreamGit(originError: 'fatal: could not read Username');

    $stable = platformUpstream()['stable'];

    expect($stable['state'])->toBe('unknown')
        ->and($stable['reason'])->toContain('stable head could not be read');
});

test('an unreadable origin never hides the resolved upstream head', function (): void {
    // Lane isolation (#482): the upstream probe authenticates independently,
    // so its result stays displayed while both origin lanes grey out.
    fakeUpstreamGit(originError: 'fatal: could not read Username');

    $upstream = platformUpstream();

    expect($upstream['head'])->not->toBeNull()
        ->and($upstream['head']['sha'])->toBe(UPSTREAM_HEAD_SHA)
        ->and($upstream['checkout']['state'])->toBe('unknown')
        ->and($upstream['stable']['state'])->toBe('unknown');
});

test('the stable comparison is pinned to origin/master even when the checkout sits on another branch', function (): void {
    fakeUpstreamGit(localBranch: 'feature/parked', stableCounts: [2, 0], stableSha: UPSTREAM_STABLE_SHA);

    expect(platformUpstream()['stable']['missing'])->toBe(2);
});

test('origin lookups authenticate with the origin owner token so private forks resolve', function (): void {
    app(GitHubTokenStore::class)->saveToken('operator', 'ghp_fork_token');

    $sawToken = false;
    Process::fake(function ($process) use (&$sawToken) {
        $command = gitCommandWithoutConfig($process->command);

        if ($command === ['git', 'ls-remote', '--exit-code', 'origin', 'refs/heads/master']) {
            // The token travels as a git config ENV pair (an Authorization
            // header), never in argv where `ps` would print it.
            $sawToken = collect($process->environment)->contains(fn ($value) => str_contains((string) $value, base64_encode('x-access-token:ghp_fork_token')));

            return Process::result(UPSTREAM_LOCAL_SHA."\trefs/heads/master");
        }

        return match ($command) {
            ['git', 'remote'] => Process::result("origin\nupstream"),
            ['git', 'remote', 'get-url', 'origin'] => Process::result('https://github.com/operator/belimbing-fork.git'),
            ['git', 'remote', 'get-url', 'upstream'] => Process::result('https://github.com/BelimbingApp/belimbing.git'),
            ['git', 'status', '--porcelain=v1', '--branch'] => Process::result('## master...origin/master'),
            ['git', 'rev-parse', '--abbrev-ref', 'HEAD'] => Process::result('master'),
            ['git', 'log', '-1', '--format=%H%x1f%cI%x1f%an%x1f%s'] => Process::result(UPSTREAM_LOCAL_SHA."\x1f".now()->toIso8601String().UPSTREAM_TRAILER),
            ['git', 'ls-remote', '--symref', 'upstream', 'HEAD'] => Process::result("ref: refs/heads/main\tHEAD\n".UPSTREAM_HEAD_SHA."\tHEAD"),
            ['git', 'show', '-s', '--format=%H%x1f%cI%x1f%an%x1f%s', UPSTREAM_HEAD_SHA] => Process::result(UPSTREAM_HEAD_SHA."\x1f".now()->toIso8601String().UPSTREAM_TRAILER),
            default => Process::result(),
        };
    });

    platformUpstream();

    expect($sawToken)->toBeTrue();
});

test('non-default upstream remote and branch names configured in git config are honoured', function (): void {
    fakeUpstreamGit(remote: 'framework', configuredRemote: 'framework', configuredBranch: 'stable-2026');

    $upstream = platformUpstream();

    expect($upstream['remote'])->toBe('framework')
        ->and($upstream['branch'])->toBe('stable-2026');
});

// ---------------------------------------------------------------------------
// Page rendering: one lane row per operator question (#482).

test('the page renders the three lanes and plain Up to date only when all are settled', function (): void {
    fakeUpstreamGit(stableSha: UPSTREAM_HEAD_SHA, localSha: UPSTREAM_HEAD_SHA, stableCounts: null, checkoutCounts: null);

    $this->actingAs(createAdminUser());

    Livewire::test(Index::class)
        ->call('loadLatestStatus')
        ->assertSee('Checkout')
        ->assertSee('Fork stable')
        ->assertSee('Deploy')
        ->assertSee('Has every upstream update')
        ->assertSee('In sync')
        ->assertSee('Up to date')
        ->assertDontSee('Refresh mirror')
        ->assertDontSee('Mirror');
});

test('pending upstream updates surface on the stable lane with the integration-proposal action', function (): void {
    fakeUpstreamGit(stableCounts: [3, 41], stableSha: UPSTREAM_STABLE_SHA, localSha: UPSTREAM_STABLE_SHA, checkoutCounts: null);

    $this->actingAs(createAdminUser());

    Livewire::test(Index::class)
        ->call('loadLatestStatus')
        ->assertSee('3 upstream updates to integrate')
        ->assertSee('41 fork-own commits (expected)')
        // The gate is closed in this test environment, so the lane names the
        // need rather than offering the action; the open-gate action path is
        // covered end to end in UpstreamSyncTest.
        ->assertSee('Integration needed')
        // The lanes split what one badge used to conflate: the DEPLOY lane is
        // honestly current (this runtime matches origin) at the same time as
        // the STABLE lane needs integration. Both truths render side by side.
        ->assertSee('Up to date');
});

test('unpushed checkout commits block the deploy lane and say so', function (): void {
    fakeUpstreamGit(checkoutCounts: [3, 2], stableSha: UPSTREAM_STABLE_SHA);

    $this->actingAs(createAdminUser());

    Livewire::test(Index::class)
        ->call('loadLatestStatus')
        ->assertSee('3 to pull')
        ->assertSee('2 unpushed')
        ->assertSee('Push or reconcile first');
});

test('an origin auth failure greys the origin lanes while the upstream head stays visible', function (): void {
    fakeUpstreamGit(originError: 'fatal: could not read Username');

    $this->actingAs(createAdminUser());

    Livewire::test(Index::class)
        ->call('loadLatestStatus')
        ->assertSee('origin unreadable')
        ->assertSee('needs origin')
        ->assertSee('reachable')
        ->assertSee(substr(UPSTREAM_HEAD_SHA, 0, 7));
});
