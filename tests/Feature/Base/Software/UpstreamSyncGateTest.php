<?php

use App\Base\Software\Livewire\Deployment\Index;
use App\Base\Software\Services\UpstreamSyncGate;
use App\Core\Company\Models\Company;
use App\Core\User\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

const SYNC_GATE_LOCAL_SHA = 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeef';
const SYNC_GATE_UPSTREAM_SHA = 'cafebabecafebabecafebabecafebabecafebabe';

beforeEach(function (): void {
    Cache::flush();
    Http::fake();
});

function createUserWithoutCapabilities(): User
{
    $company = Company::factory()->create();

    return User::factory()->create(['company_id' => $company->id]);
}

/**
 * Platform is a fork with a contained upstream, matching #344's fixture shape,
 * so the page renders the upstream line the gate's statement attaches to.
 */
function fakeSyncGateUpstreamGit(): void
{
    Process::fake(function ($process) {
        return match (gitCommandWithoutConfig($process->command)) {
            ['git', 'remote'] => Process::result("origin\nupstream"),
            ['git', 'config', '--get', 'belimbing.upstream-remote'],
            ['git', 'config', '--get', 'belimbing.upstream-branch'] => Process::result('', exitCode: 1),
            ['git', 'remote', 'get-url', 'origin'] => Process::result('https://github.com/operator/belimbing-fork.git'),
            ['git', 'remote', 'get-url', 'upstream'] => Process::result('https://github.com/BelimbingApp/belimbing.git'),
            ['git', 'ls-remote', '--symref', 'upstream', 'HEAD'] => Process::result("ref: refs/heads/main\tHEAD\n".SYNC_GATE_UPSTREAM_SHA."\tHEAD"),
            ['git', 'status', '--porcelain=v1', '--branch'] => Process::result('## master...origin/master'),
            ['git', 'rev-parse', '--abbrev-ref', 'HEAD'] => Process::result('master'),
            ['git', 'log', '-1', '--format=%H%x1f%cI%x1f%an%x1f%s'] => Process::result(SYNC_GATE_LOCAL_SHA."\x1f".now()->toIso8601String()."\x1fCI\x1fCurrent"),
            ['git', 'ls-remote', '--exit-code', 'origin', 'refs/heads/master'] => Process::result(SYNC_GATE_LOCAL_SHA."\trefs/heads/master"),
            ['git', 'show', '-s', '--format=%H%x1f%cI%x1f%an%x1f%s', SYNC_GATE_UPSTREAM_SHA] => Process::result(SYNC_GATE_UPSTREAM_SHA."\x1f".now()->toIso8601String()."\x1fCI\x1fCurrent"),
            ['git', 'rev-list', '--left-right', '--count', SYNC_GATE_UPSTREAM_SHA.'...'.SYNC_GATE_LOCAL_SHA] => Process::result("0\t3"),
            default => Process::result(),
        };
    });
}

test('synchronization is unavailable on a production deployment even for a capable admin', function (): void {
    config()->set('software.deployment_role', 'production');

    $user = createAdminUser();
    $state = app(UpstreamSyncGate::class)->state($user);

    expect($state['available'])->toBeFalse()
        ->and($state['reason'])->toContain('production');
});

test('an unset deployment role fails closed with a stated reason', function (): void {
    config()->set('software.deployment_role', null);

    $user = createAdminUser();
    $state = app(UpstreamSyncGate::class)->state($user);

    expect($state['available'])->toBeFalse()
        ->and($state['reason'])->toContain('declares no role');
});

test('an unrecognised deployment role fails closed, not open', function (): void {
    config()->set('software.deployment_role', 'dev-box');

    $user = createAdminUser();
    $state = app(UpstreamSyncGate::class)->state($user);

    expect($state['available'])->toBeFalse()
        ->and($state['reason'])->toContain('dev-box');
});

test('a development deployment without the capability is still closed', function (): void {
    config()->set('software.deployment_role', 'development');

    $user = createUserWithoutCapabilities();
    $state = app(UpstreamSyncGate::class)->state($user);

    expect($state['available'])->toBeFalse()
        ->and($state['reason'])->toContain(UpstreamSyncGate::CAPABILITY);
});

test('a development deployment with the capability opens the gate', function (): void {
    config()->set('software.deployment_role', 'development');

    $user = createAdminUser();
    $state = app(UpstreamSyncGate::class)->state($user);

    expect($state['available'])->toBeTrue()
        ->and($state['reason'])->toBeNull();
});

test('a staging deployment with the capability opens the gate', function (): void {
    config()->set('software.deployment_role', 'staging');

    $user = createAdminUser();

    expect(app(UpstreamSyncGate::class)->state($user)['available'])->toBeTrue();
});

test('the server-side boundary throws for every closed condition and passes when open', function (): void {
    $gate = app(UpstreamSyncGate::class);
    $admin = createAdminUser();
    $incapable = createUserWithoutCapabilities();

    config()->set('software.deployment_role', 'production');
    expect(fn () => $gate->authorize($admin))->toThrow(AuthorizationException::class);

    config()->set('software.deployment_role', null);
    expect(fn () => $gate->authorize($admin))->toThrow(AuthorizationException::class);

    config()->set('software.deployment_role', 'development');
    expect(fn () => $gate->authorize($incapable))->toThrow(AuthorizationException::class);
    expect(fn () => $gate->authorize(null))->toThrow(AuthorizationException::class);

    $gate->authorize($admin);
    expect(true)->toBeTrue();
});

test('a closed gate is an explanation on the page, and read-only visibility is unaffected', function (): void {
    config()->set('software.deployment_role', 'production');
    fakeSyncGateUpstreamGit();

    $user = createAdminUser();
    $this->actingAs($user);

    Livewire::test(Index::class)
        ->call('loadLatestStatus')
        // #344's visibility renders regardless of the gate.
        ->assertSee('Upstream contained')
        // The gate's state is stated, not hidden.
        ->assertSee('unavailable on a production deployment');
});

test('an open gate states availability on the page', function (): void {
    config()->set('software.deployment_role', 'development');
    fakeSyncGateUpstreamGit();

    $user = createAdminUser();
    $this->actingAs($user);

    Livewire::test(Index::class)
        ->call('loadLatestStatus')
        ->assertSee('Upstream synchronization is available on this deployment.');
});
