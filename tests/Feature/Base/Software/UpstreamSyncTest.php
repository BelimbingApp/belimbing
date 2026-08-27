<?php

use App\Base\Software\Livewire\Deployment\Index;
use App\Base\Software\Services\UpstreamSyncService;
use App\Core\Company\Models\Company;
use App\Core\User\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

const SYNC_LOCAL_SHA = 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeef';
const SYNC_UPSTREAM_SHA = 'cafebabecafebabecafebabecafebabecafebabe';
const SYNC_OLD_MIRROR_SHA = 'a11ce000a11ce000a11ce000a11ce000a11ce000';
const SYNC_STABLE_SHA = 'beefbeefbeefbeefbeefbeefbeefbeefbeefbeef';
const SYNC_TREE_OID = 'facefeedfacefeedfacefeedfacefeedfacefeed';
const SYNC_RC_SHA = 'ba5eba11ba5eba11ba5eba11ba5eba11ba5eba11';

beforeEach(function (): void {
    Cache::flush();
    Http::fake();
    config()->set('software.deployment_role', 'development');
});

function syncIncapableUser(): User
{
    $company = Company::factory()->create();

    return User::factory()->create(['company_id' => $company->id]);
}

/**
 * One fixture for the whole sync matrix. Domain axis: mirror absent /
 * fast-forwardable / diverged, integration clean / conflicting. Environment
 * axis: no upstream remote, rc already existing, push rejected — plus the gate
 * states handled in the tests themselves. $ran records every mutating command
 * so refusals can assert nothing was pushed.
 *
 * @param  array<string, bool>  $ran
 */
function fakeSyncGit(
    array &$ran,
    bool $hasUpstreamRemote = true,
    ?string $mirrorSha = null,
    bool $mirrorDiverged = false,
    bool $rcExists = false,
    bool $integrationConflicts = false,
    bool $pushRejected = false,
): void {
    Process::fake(function ($process) use (&$ran, $hasUpstreamRemote, $mirrorSha, $mirrorDiverged, $rcExists, $integrationConflicts, $pushRejected) {
        $command = gitCommandWithoutConfig($process->command);

        if (($command[1] ?? null) === 'push') {
            $ran['push '.implode(' ', array_slice($command, 2))] = true;

            return $pushRejected
                ? Process::result(errorOutput: 'remote: permission denied', exitCode: 1)
                : Process::result('');
        }

        if (($command[1] ?? null) === 'fetch') {
            $ran['fetch '.implode(' ', array_slice($command, 2))] = true;

            return Process::result('');
        }

        return match ($command) {
            ['git', 'remote'] => Process::result($hasUpstreamRemote ? "origin\nupstream" : 'origin'),
            ['git', 'config', '--get', 'belimbing.upstream-remote'],
            ['git', 'config', '--get', 'belimbing.upstream-branch'] => Process::result('', exitCode: 1),
            ['git', 'remote', 'get-url', 'origin'] => Process::result('https://github.com/operator/belimbing-fork.git'),
            ['git', 'remote', 'get-url', 'upstream'] => Process::result('https://github.com/BelimbingApp/belimbing.git'),
            ['git', 'ls-remote', '--symref', 'upstream', 'HEAD'] => Process::result("ref: refs/heads/main\tHEAD\n".SYNC_UPSTREAM_SHA."\tHEAD"),
            ['git', 'ls-remote', '--exit-code', 'origin', 'refs/heads/main'] => $mirrorSha !== null
                ? Process::result($mirrorSha."\trefs/heads/main")
                : Process::result('', exitCode: 2),
            ['git', 'ls-remote', '--exit-code', 'origin', 'refs/heads/rc'] => $rcExists
                ? Process::result(SYNC_RC_SHA."\trefs/heads/rc")
                : Process::result('', exitCode: 2),
            ['git', 'merge-base', '--is-ancestor', (string) $mirrorSha, SYNC_UPSTREAM_SHA] => $mirrorDiverged
                ? Process::result('', exitCode: 1)
                : Process::result(''),
            ['git', 'rev-parse', 'refs/remotes/origin/master'] => Process::result(SYNC_STABLE_SHA),
            ['git', 'rev-parse', 'refs/remotes/origin/main'] => Process::result(SYNC_UPSTREAM_SHA),
            ['git', 'merge-tree', '--write-tree', '--name-only', SYNC_STABLE_SHA, SYNC_UPSTREAM_SHA] => $integrationConflicts
                ? Process::result(SYNC_TREE_OID."\napp/Base/Foundation/Kernel.php\nconfig/app.php\n\nAuto-merging config/app.php\nCONFLICT (content): Merge conflict in config/app.php", exitCode: 1)
                : Process::result(SYNC_TREE_OID),
            ['git', 'commit-tree', SYNC_TREE_OID, '-p', SYNC_STABLE_SHA, '-p', SYNC_UPSTREAM_SHA, '-m', 'rc: integrate upstream/main into master'] => Process::result(SYNC_RC_SHA),
            default => Process::result(),
        };
    });
}

test('an absent mirror is created from the upstream head', function (): void {
    $ran = [];
    fakeSyncGit($ran, mirrorSha: null);

    $result = app(UpstreamSyncService::class)->refreshMirror(createAdminUser());

    expect($result['ok'])->toBeTrue()
        ->and($result['message'])->toContain('Mirror created')
        ->and($ran)->toHaveKey('push origin '.SYNC_UPSTREAM_SHA.':refs/heads/main');
});

test('a fast-forwardable mirror is refreshed', function (): void {
    $ran = [];
    fakeSyncGit($ran, mirrorSha: SYNC_OLD_MIRROR_SHA);

    $result = app(UpstreamSyncService::class)->refreshMirror(createAdminUser());

    expect($result['ok'])->toBeTrue()
        ->and($result['message'])->toContain('fast-forwarded')
        ->and($ran)->toHaveKey('push origin '.SYNC_UPSTREAM_SHA.':refs/heads/main');
});

test('an already-current mirror is reported without a push', function (): void {
    $ran = [];
    fakeSyncGit($ran, mirrorSha: SYNC_UPSTREAM_SHA);

    $result = app(UpstreamSyncService::class)->refreshMirror(createAdminUser());

    expect($result['ok'])->toBeTrue()
        ->and($result['message'])->toContain('already current')
        ->and(array_filter($ran, fn ($v, $k) => str_starts_with($k, 'push'), ARRAY_FILTER_USE_BOTH))->toBe([]);
});

test('a diverged mirror is refused with the condition named, and nothing is pushed', function (): void {
    $ran = [];
    fakeSyncGit($ran, mirrorSha: SYNC_OLD_MIRROR_SHA, mirrorDiverged: true);

    $result = app(UpstreamSyncService::class)->refreshMirror(createAdminUser());

    expect($result['ok'])->toBeFalse()
        ->and($result['message'])->toContain('committed to the mirror directly')
        ->and(array_filter($ran, fn ($v, $k) => str_starts_with($k, 'push'), ARRAY_FILTER_USE_BOTH))->toBe([]);
});

test('a clean integration cuts rc in the object database and pushes it', function (): void {
    $ran = [];
    fakeSyncGit($ran);

    $result = app(UpstreamSyncService::class)->cutReleaseCandidate(createAdminUser());

    expect($result['ok'])->toBeTrue()
        ->and($result['message'])->toContain('Open the pull request')
        ->and($ran)->toHaveKey('push origin '.SYNC_RC_SHA.':refs/heads/rc');
});

test('a conflicting integration aborts, names the files, and pushes nothing', function (): void {
    $ran = [];
    fakeSyncGit($ran, integrationConflicts: true);

    $result = app(UpstreamSyncService::class)->cutReleaseCandidate(createAdminUser());

    expect($result['ok'])->toBeFalse()
        ->and($result['message'])->toContain('app/Base/Foundation/Kernel.php')
        ->and($result['message'])->toContain('working tree was not touched')
        ->and($result['message'])->not->toContain('Auto-merging')
        ->and(array_filter($ran, fn ($v, $k) => str_starts_with($k, 'push'), ARRAY_FILTER_USE_BOTH))->toBe([]);
});

test('an existing rc from a previous round refuses the cut (per-cycle policy)', function (): void {
    $ran = [];
    fakeSyncGit($ran, rcExists: true);

    $result = app(UpstreamSyncService::class)->cutReleaseCandidate(createAdminUser());

    expect($result['ok'])->toBeFalse()
        ->and($result['message'])->toContain('already exists')
        ->and(array_filter($ran, fn ($v, $k) => str_starts_with($k, 'push'), ARRAY_FILTER_USE_BOTH))->toBe([]);
});

test('a checkout with no upstream remote states that, for both actions', function (): void {
    $ran = [];
    fakeSyncGit($ran, hasUpstreamRemote: false);

    $service = app(UpstreamSyncService::class);
    $user = createAdminUser();

    expect($service->refreshMirror($user)['message'])->toContain('no upstream remote')
        ->and($service->cutReleaseCandidate($user)['message'])->toContain('no upstream remote')
        ->and($ran)->toBe([]);
});

test('a rejected push is a reported failure with the remote detail', function (): void {
    $ran = [];
    fakeSyncGit($ran, mirrorSha: SYNC_OLD_MIRROR_SHA, pushRejected: true);

    $result = app(UpstreamSyncService::class)->refreshMirror(createAdminUser());

    expect($result['ok'])->toBeFalse()
        ->and($result['detail'])->toContain('permission denied');
});

test('the gate stops both actions in production and without the capability, before any git runs', function (): void {
    $ran = [];
    fakeSyncGit($ran);

    $service = app(UpstreamSyncService::class);
    $admin = createAdminUser();
    $incapable = syncIncapableUser();

    config()->set('software.deployment_role', 'production');
    expect(fn () => $service->refreshMirror($admin))->toThrow(AuthorizationException::class);
    expect(fn () => $service->cutReleaseCandidate($admin))->toThrow(AuthorizationException::class);

    config()->set('software.deployment_role', 'development');
    expect(fn () => $service->refreshMirror($incapable))->toThrow(AuthorizationException::class);
    expect(fn () => $service->cutReleaseCandidate($incapable))->toThrow(AuthorizationException::class);

    expect($ran)->toBe([]);
});

test('a gate that closes between render and click stops the Livewire action at the server', function (): void {
    $ran = [];
    fakeSyncGit($ran);

    $user = createAdminUser();
    $this->actingAs($user);

    $component = Livewire::test(Index::class)->call('loadLatestStatus');

    // The page rendered with the gate open; it closes before the click lands.
    config()->set('software.deployment_role', 'production');

    $component->call('refreshMirror');

    expect($ran)->toBe([]);
});

test('the sync actions run end to end through the page when the gate is open', function (): void {
    $ran = [];
    fakeSyncGit($ran);

    $user = createAdminUser();
    $this->actingAs($user);

    Livewire::test(Index::class)
        ->call('loadLatestStatus')
        ->assertSee('Refresh mirror')
        ->assertSee('Cut release candidate')
        ->call('refreshMirror');

    expect($ran)->toHaveKey('push origin '.SYNC_UPSTREAM_SHA.':refs/heads/main');
});
