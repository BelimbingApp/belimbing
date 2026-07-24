<?php

use App\Base\Settings\Contracts\SettingsService;
use App\Base\Software\Livewire\Deployment\Index;
use App\Base\Software\Services\DeploymentMaintenanceGuard;
use App\Base\Software\Services\DeploymentRunHistory;
use App\Base\Software\Services\DeploymentService;
use App\Base\Software\Services\DistributionBundleRepository;
use App\Base\Software\Services\FrankenPhpDomainRuntimeReloader;
use App\Base\Software\Services\SoftwareUpdateLauncher;
use App\Base\Support\DetachedProcessLauncher;
use App\Base\Support\PhpCli;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

const DEPLOYMENT_UPDATE_SHA = 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeef';
const DEPLOYMENT_UPDATE_COMMIT_TRAILER = "\x1fCI\x1fCurrent";
const DEPLOYMENT_UPDATE_REMOTE_SHA = 'feedfacefeedfacefeedfacefeedfacefeedface';
const DEPLOYMENT_UPDATE_FRONTEND_BUILT = 'Frontend assets built.';
const DEPLOYMENT_UPDATE_LAST_RUN_LABEL = 'Last run';
const DEPLOYMENT_UPDATE_VERIFIED_PLATFORM = 'Verified: Belimbing (platform) is at deadbee and matches main.';
const DEPLOYMENT_UPDATE_COMPLETE = 'Update complete. Selected Distribution Bundles are up to date and workers were reloaded.';
const DEPLOYMENT_UPDATE_REMOTE = 'https://github.com/BelimbingApp/belimbing.git';
const DEPLOYMENT_UPDATE_BRANCH_ARG = '--abbrev-ref';
const DEPLOYMENT_UPDATE_LOG_FORMAT = '--format=%H%x1f%cI%x1f%an%x1f%s';
const DEPLOYMENT_UPDATE_FF_ONLY = '--ff-only';
const DEPLOYMENT_UPDATE_RELOADED = 'Web workers reloaded.';
const DEPLOYMENT_UPDATE_SCHEDULED_MESSAGE = 'Software update scheduled in a detached process.';
const DEPLOYMENT_UPDATE_PULLING_PLATFORM = 'Pulling Belimbing (platform)…';
const DEPLOYMENT_UPDATE_ADMIN_HOST = '127.0.0.1';
const DEPLOYMENT_UPDATE_ADMIN_HOST_ENV = 'CADDY_SERVER_ADMIN_HOST='.DEPLOYMENT_UPDATE_ADMIN_HOST;
const DEPLOYMENT_UPDATE_ADMIN_BASE_URL = 'http://127.0.0.1:2643';
const DEPLOYMENT_UPDATE_ADMIN_CONFIG_PATH = '/config/apps/frankenphp';
const DEPLOYMENT_UPDATE_WORKERS_RESTART_PATH = '/frankenphp/workers/restart';

final class DeploymentUpdateGitLaunchException extends RuntimeException {}

beforeEach(function (): void {
    Cache::flush();
    app(SettingsService::class)->forget('system.update.frankenphp.reload_state');
});

function fakeDeploymentUpdateProcesses(string $sha = DEPLOYMENT_UPDATE_SHA, ?string $remoteError = null, ?string $remoteSha = null): void
{
    Process::fake(function ($process) use ($sha, $remoteError, $remoteSha) {
        return fakeDeploymentUpdateGitResult($process->command, $sha, $remoteError, $remoteSha) ?? Process::result();
    });
}

function fakeDeploymentUpdateGitResult(array $command, string $sha = DEPLOYMENT_UPDATE_SHA, ?string $remoteError = null, ?string $remoteSha = null): mixed
{
    // When $remoteSha is null, the remote HEAD matches the local SHA so bundles
    // report up_to_date. Pass a distinct $remoteSha to simulate a bundle that
    // is behind its remote (up_to_date === false after loadLatestStatus).
    $remoteSha ??= $sha;

    return match (gitCommandWithoutConfig($command)) {
        ['git', 'remote', 'get-url', 'origin'] => Process::result(DEPLOYMENT_UPDATE_REMOTE),
        ['git', 'status', '--porcelain=v1', '--branch'] => Process::result('## main...origin/main'),
        ['git', 'rev-parse', DEPLOYMENT_UPDATE_BRANCH_ARG, 'HEAD'] => Process::result('main'),
        ['git', 'log', '-1', DEPLOYMENT_UPDATE_LOG_FORMAT] => Process::result($sha."\x1f".now()->toIso8601String().DEPLOYMENT_UPDATE_COMMIT_TRAILER),
        ['git', 'ls-remote', '--exit-code', 'origin', 'refs/heads/main'] => $remoteError === null
            ? Process::result($remoteSha."\trefs/heads/main")
            : Process::result(errorOutput: $remoteError, exitCode: 1),
        ['git', 'show', '-s', DEPLOYMENT_UPDATE_LOG_FORMAT, $remoteSha] => Process::result($remoteSha."\x1f".now()->toIso8601String().DEPLOYMENT_UPDATE_COMMIT_TRAILER),
        ['git', 'pull', DEPLOYMENT_UPDATE_FF_ONLY] => Process::result('Already up to date.'),
        default => null,
    };
}

function fakeDeploymentUpdateHttp(bool $reloadOk = true): void
{
    Http::fake([
        DEPLOYMENT_UPDATE_ADMIN_HOST.':*' => $reloadOk
            ? deploymentWorkerConfigResponse()
            : Http::response('', 500),
        '*' => Http::response([], 200),
    ]);
}

function deploymentCommandContains(array $command, string $needle): bool
{
    return collect($command)->contains(fn (string $part): bool => str_contains($part, $needle));
}

function deploymentUniqueRemoteCheckCount(array $status): int
{
    return collect($status)
        ->filter(fn (array $entry): bool => is_string($entry['repo'] ?? null) && is_string($entry['branch'] ?? null))
        ->map(fn (array $entry): string => $entry['repo'].'|'.$entry['branch'])
        ->unique()
        ->count();
}

function withDeploymentAdminEnv(string $host, string $port, Closure $callback): void
{
    // The application reads its bootstrapped config, while subprocess-adjacent
    // paths may still inspect the process environment. Keep both views aligned.
    $savedEnv = [];
    $savedConfig = [
        'app.caddy_server_admin_host' => config('app.caddy_server_admin_host'),
        'app.caddy_server_admin_port' => config('app.caddy_server_admin_port'),
    ];
    config([
        'app.caddy_server_admin_host' => $host,
        'app.caddy_server_admin_port' => $port,
    ]);

    foreach (['CADDY_SERVER_ADMIN_HOST' => $host, 'CADDY_SERVER_ADMIN_PORT' => $port] as $key => $value) {
        $savedEnv[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key) === false ? null : getenv($key)];
        putenv("$key=$value");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    try {
        $callback();
    } finally {
        config($savedConfig);

        foreach ($savedEnv as $key => [$env, $server, $getenv]) {
            if ($env === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $env;
            }

            if ($server === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $server;
            }

            $getenv === null ? putenv($key) : putenv("$key=$getenv");
        }
    }
}

function withDeploymentOctaneState(?array $state, Closure $callback): void
{
    // These tests exercise the state-file resolution chain, so neither
    // bootstrapped config nor process environment may expose a pinned endpoint.
    $savedEnv = [];
    $savedConfig = [
        'app.caddy_server_admin_host' => config('app.caddy_server_admin_host'),
        'app.caddy_server_admin_port' => config('app.caddy_server_admin_port'),
    ];
    config([
        'app.caddy_server_admin_host' => null,
        'app.caddy_server_admin_port' => null,
    ]);

    foreach (['CADDY_SERVER_ADMIN_HOST', 'CADDY_SERVER_ADMIN_PORT'] as $key) {
        $savedEnv[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key) === false ? null : getenv($key)];
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }

    $statePath = storage_path('logs/octane-server-state.json');
    $backup = is_file($statePath) ? file_get_contents($statePath) : null;

    $state === null
        ? @unlink($statePath)
        : file_put_contents($statePath, json_encode($state));

    try {
        $callback();
    } finally {
        $backup === null ? @unlink($statePath) : file_put_contents($statePath, $backup);
        config($savedConfig);

        foreach ($savedEnv as $key => [$env, $server, $getenv]) {
            if ($env !== null) {
                $_ENV[$key] = $env;
            }
            if ($server !== null) {
                $_SERVER[$key] = $server;
            }
            if ($getenv !== null) {
                putenv("$key=$getenv");
            }
        }
    }
}

function expectDeploymentReloadUsesAdminEndpoint(string $baseUrl): void
{
    fakeDeploymentUpdateProcesses();
    $healthUrl = rtrim((string) config('app.url'), '/').'/up';

    Http::fake([
        deploymentAdminConfigUrl($baseUrl) => deploymentWorkerConfigResponse(),
        deploymentAdminRestartUrl($baseUrl) => Http::response('', 200),
        $healthUrl => Http::response('', 200),
        '*' => Http::response('', 500),
    ]);

    $log = app(DeploymentService::class)->reload();

    expect($log)->toContain(DEPLOYMENT_UPDATE_RELOADED);
    Http::assertSent(fn ($request): bool => $request->url() === deploymentAdminRestartUrl($baseUrl));
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), ':2019/'));
}

/**
 * @param  array<string, mixed>  $options
 */
function expectDeploymentRuntimeReloadCommandSucceeds(array $options = []): void
{
    fakeDeploymentUpdateProcesses();
    fakeDeploymentUpdateHttp();
    Cache::put(FrankenPhpDomainRuntimeReloader::PENDING_CACHE_KEY, now()->utc()->toIso8601String(), now()->addMinute());

    $status = Artisan::call('blb:domain-runtime:reload', ['--delay' => 0, ...$options]);
    $stored = app(SettingsService::class)->get('system.update.frankenphp.last_reload');
    $state = app(DeploymentRunHistory::class)->reloadState();

    expect($status)->toBe(0)
        ->and($stored)->toBeArray()
        ->and($stored['ok'])->toBeTrue()
        ->and($stored['message'])->toBe(DEPLOYMENT_UPDATE_RELOADED)
        ->and($state)->toMatchArray(['status' => 'success', 'message' => DEPLOYMENT_UPDATE_RELOADED])
        ->and(Cache::has(FrankenPhpDomainRuntimeReloader::PENDING_CACHE_KEY))->toBeFalse();

    Process::assertRan(fn ($process): bool => $process->command === PhpCli::current()->artisan(['about', '--only=environment']));
}

function beginDeploymentCommandRun(string $runId): DeploymentRunHistory
{
    $history = app(DeploymentRunHistory::class);
    $history->beginDeploymentRun($runId, ['platform'], 'Scheduled.');
    Cache::lock(SoftwareUpdateLauncher::LOCK_KEY, 3600, $runId)->get();

    return $history;
}

function expectDeploymentCommandMaintenance(string $runId, bool $reloadSucceeded): void
{
    $maintenance = Mockery::mock(DeploymentMaintenanceGuard::class);
    $maintenance->shouldReceive('arm')->once()->with($runId);
    $maintenance->shouldReceive('enter')->once()->with($runId);
    $maintenance->shouldReceive('renew')->atLeast()->once()->with($runId)->andReturnTrue();

    if ($reloadSucceeded) {
        $maintenance->shouldReceive('leave')->twice()->with($runId)->andReturnTrue();
        $maintenance->shouldReceive('disarm')->twice()->with($runId);
    } else {
        $maintenance->shouldNotReceive('leave');
        $maintenance->shouldReceive('disarm')->once()->with($runId);
    }

    app()->instance(DeploymentMaintenanceGuard::class, $maintenance);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, array<string, mixed>>
 */
function deploymentOctaneState(array $overrides = []): array
{
    return [
        'state' => [
            ...$overrides,
            'adminHost' => DEPLOYMENT_UPDATE_ADMIN_HOST,
            'adminPort' => 2643,
        ],
    ];
}

function deploymentAdminConfigUrl(string $baseUrl): string
{
    return $baseUrl.DEPLOYMENT_UPDATE_ADMIN_CONFIG_PATH;
}

function deploymentAdminRestartUrl(string $baseUrl): string
{
    return $baseUrl.DEPLOYMENT_UPDATE_WORKERS_RESTART_PATH;
}

function deploymentWorkerConfigResponse(): mixed
{
    return Http::response(['workers' => [['file_name' => public_path('frankenphp-worker.php')]]], 200);
}

function fakeDeploymentTimedOutAdminApiResponse(
    string $requestUrl,
    string $requestMethod,
    int &$getAttempts,
): mixed {
    if (! deploymentIsAdminApiReloadUrl($requestUrl)) {
        return fakeDeploymentReloadFallbackResponse($requestUrl);
    }

    if ($requestMethod !== 'GET') {
        return Http::response('', 200);
    }

    $getAttempts++;

    if ($getAttempts === 1) {
        throw new ConnectionException(
            'cURL error 28: Operation timed out after 10008 milliseconds with 0 bytes received for '.
            deploymentAdminConfigUrl(DEPLOYMENT_UPDATE_ADMIN_BASE_URL)
        );
    }

    return deploymentWorkerConfigResponse();
}

function deploymentIsAdminApiReloadUrl(string $requestUrl): bool
{
    return in_array($requestUrl, [
        deploymentAdminConfigUrl(DEPLOYMENT_UPDATE_ADMIN_BASE_URL),
        deploymentAdminRestartUrl(DEPLOYMENT_UPDATE_ADMIN_BASE_URL),
    ], true);
}

function fakeDeploymentReloadFallbackResponse(string $requestUrl): mixed
{
    if ($requestUrl === rtrim((string) config('app.url'), '/').'/up') {
        return Http::response('', 200);
    }

    return Http::response('', 500);
}

test('deployment page lists Distribution Bundles with status for admins', function (): void {
    $user = createAdminUser();
    fakeDeploymentUpdateProcesses();
    Http::fake();

    $this->actingAs($user)
        ->get(route('admin.system.software.updates.index'))
        ->assertOk()
        ->assertSee('Updates')
        ->assertSee('Distribution Bundles')
        ->assertSee('Distribution Bundle')
        ->assertSee('A Distribution Bundle is BLB&#039;s installable, versioned code bundle.', false)
        ->assertSee('FrankenPHP workers')
        ->assertSee('No reload has been recorded yet.')
        ->assertSee('Belimbing (platform)')
        ->assertSee('BelimbingApp/belimbing') // discovered platform bundle's Git repository
        ->assertSee('Checking latest')
        ->assertSee('Reload FrankenPHP')
        ->assertSee('Streaming live output. You can dismiss this window; the run continues.')
        ->assertSee('x-show="isFloating()"', false)
        ->assertDontSee('isFloating() && ! running && ! refreshing', false)
        ->assertDontSee('if (this.running || this.refreshing)', false)
        ->assertDontSee('Keep this tab open')
        ->assertSee('does not pull code, install dependencies, build assets, or run migrations')
        ->assertDontSee('Code repositories');

    Http::assertSentCount(0);
});

test('deployment page defers remote latest checks until livewire init', function (): void {
    $user = createAdminUser();
    $lsRemoteCount = 0;

    Process::fake(function ($process) use (&$lsRemoteCount) {
        if (gitCommandWithoutConfig($process->command) === ['git', 'ls-remote', '--exit-code', 'origin', 'refs/heads/main']) {
            $lsRemoteCount++;
        }

        return fakeDeploymentUpdateGitResult($process->command) ?? Process::result();
    });
    Http::fake();

    $this->actingAs($user)
        ->get(route('admin.system.software.updates.index'))
        ->assertOk()
        ->assertSee('Checking latest')
        ->assertDontSee('Up to date');

    expect($lsRemoteCount)->toBe(0);

    Livewire::test(Index::class)
        ->call('loadLatestStatus')
        ->assertSee('Up to date');

    expect($lsRemoteCount)->toBeGreaterThan(0);
});

test('failed remote checks name the repos instead of assuming they are private', function (): void {
    $user = createAdminUser();
    fakeDeploymentUpdateProcesses(remoteError: 'fatal: unable to access repository');
    Http::fake();

    $this->actingAs($user);

    Livewire::test(Index::class)
        ->call('loadLatestStatus')
        ->assertSee('Could not check latest commits for these Distribution Bundles: BelimbingApp/belimbing')
        ->assertSee('Public repositories do not need a token')
        ->assertSee('Could not read latest commit for BelimbingApp/belimbing@main via git ls-remote (fatal: unable to access repository)')
        ->assertDontSee('A private repository could not be checked');

    Http::assertSentCount(0);
});

test('deployment local status tolerates git launch failures', function (): void {
    Process::fake(fn () => throw new DeploymentUpdateGitLaunchException('git executable was not found'));

    $expectedBundleCount = count(app(DistributionBundleRepository::class)->distributions());
    $status = app(DeploymentService::class)->localStatus();

    expect($status)->toHaveCount($expectedBundleCount)
        ->and(collect($status)->pluck('current')->filter()->all())->toBe([])
        ->and(collect($status)->pluck('latest')->filter()->all())->toBe([]);
});

test('deployment status reports remote process pool failures as row errors', function (): void {
    Process::fake(function ($process) {
        if (gitCommandWithoutConfig($process->command) === ['git', 'ls-remote', '--exit-code', 'origin', 'refs/heads/main']) {
            throw new DeploymentUpdateGitLaunchException('process pool unavailable');
        }

        return fakeDeploymentUpdateGitResult($process->command) ?? Process::result();
    });

    $status = app(DistributionBundleRepository::class)->status(useRemoteCache: false);

    expect(collect($status)->pluck('error')->filter()->first())
        ->toContain('Could not start Git remote status checks: process pool unavailable');
});

test('deployment status does not cache transient remote failures', function (): void {
    $lsRemoteCount = 0;

    Process::fake(function ($process) use (&$lsRemoteCount) {
        if (gitCommandWithoutConfig($process->command) === ['git', 'ls-remote', '--exit-code', 'origin', 'refs/heads/main']) {
            $lsRemoteCount++;

            return Process::result(errorOutput: 'temporary network failure', exitCode: 1);
        }

        return fakeDeploymentUpdateGitResult($process->command) ?? Process::result();
    });

    $repository = app(DistributionBundleRepository::class);

    $first = $repository->status();
    $repository->status();

    $uniqueRemoteChecks = deploymentUniqueRemoteCheckCount($first);

    expect($lsRemoteCount)->toBe($uniqueRemoteChecks * 2);
});

test('deployment status deduplicates remote latest checks in each render', function (): void {
    $lsRemoteCount = 0;
    Process::fake(function ($process) use (&$lsRemoteCount) {
        if (gitCommandWithoutConfig($process->command) === ['git', 'ls-remote', '--exit-code', 'origin', 'refs/heads/main']) {
            $lsRemoteCount++;
        }

        return fakeDeploymentUpdateGitResult($process->command) ?? Process::result();
    });

    $repository = app(DistributionBundleRepository::class);
    $first = $repository->status();
    $uniqueRemoteChecks = deploymentUniqueRemoteCheckCount($first);

    expect($lsRemoteCount)->toBe($uniqueRemoteChecks);

    $repository->status();

    expect($lsRemoteCount)->toBe($uniqueRemoteChecks);

    $beforeBypass = $lsRemoteCount;
    $repository->status(useRemoteCache: false);

    expect($lsRemoteCount - $beforeBypass)->toBe($uniqueRemoteChecks)
        ->and($first)->toHaveCount(count($repository->distributions()));
});

test('reload only schedules a graceful worker reload and records a log', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);
    Cache::forget(FrankenPhpDomainRuntimeReloader::PENDING_CACHE_KEY);
    fakeDeploymentUpdateProcesses();
    Http::fake();

    try {
        $component = Livewire::test(Index::class)
            ->call('reloadOnly')
            ->assertDispatched('run-finished', status: 'pending', refresh: false)
            ->assertHasNoErrors();

        expect($component->get('log'))->toBe(['Runtime reload scheduled in the background.'])
            ->and(Cache::has(FrankenPhpDomainRuntimeReloader::PENDING_CACHE_KEY))->toBeTrue();

        expect(app(DeploymentRunHistory::class)->reloadState())->toMatchArray([
            'status' => 'pending',
            'message' => 'Runtime reload scheduled in the background.',
        ]);

        Process::assertRan(fn ($process): bool => deploymentCommandContains($process->command, 'blb:domain-runtime:reload')
            && deploymentCommandContains($process->command, '--clear-runtime-caches'));
        Http::assertNothingSent();
    } finally {
        Cache::forget(FrankenPhpDomainRuntimeReloader::PENDING_CACHE_KEY);
    }
});

test('domain runtime reload starts in a detached background command', function (): void {
    Cache::forget(FrankenPhpDomainRuntimeReloader::PENDING_CACHE_KEY);
    Process::fake();

    try {
        $log = app(FrankenPhpDomainRuntimeReloader::class)->reloadAfterDomainChange();

        expect($log)->toContain('Domain runtime reload scheduled in the background.');

        Process::assertRan(fn ($process): bool => deploymentCommandContains($process->command, 'blb:domain-runtime:reload')
            && ! deploymentCommandContains($process->command, '--clear-runtime-caches'));

        expect(app(FrankenPhpDomainRuntimeReloader::class)->reloadAfterDomainChange())
            ->toContain('Domain runtime reload is already scheduled.');
    } finally {
        Cache::forget(FrankenPhpDomainRuntimeReloader::PENDING_CACHE_KEY);
    }
});

test('software update runtime reload starts in a detached background command with cache clearing', function (): void {
    Cache::forget(FrankenPhpDomainRuntimeReloader::PENDING_CACHE_KEY);
    Process::fake();

    try {
        $log = app(FrankenPhpDomainRuntimeReloader::class)->reloadAfterSoftwareUpdate();

        expect($log)->toContain('Runtime reload scheduled in the background.');

        Process::assertRan(fn ($process): bool => deploymentCommandContains($process->command, 'blb:domain-runtime:reload')
            && deploymentCommandContains($process->command, '--clear-runtime-caches'));
    } finally {
        Cache::forget(FrankenPhpDomainRuntimeReloader::PENDING_CACHE_KEY);
    }
});

test('domain runtime reload command reloads workers without clearing runtime caches', function (): void {
    expectDeploymentRuntimeReloadCommandSucceeds();
});

test('software update runtime reload command reloads workers after clearing runtime caches', function (): void {
    expectDeploymentRuntimeReloadCommandSucceeds(['--clear-runtime-caches' => true]);
});

test('component updates launch a durable process instead of updating inside the web worker', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);
    fakeDeploymentUpdateProcesses();
    Http::fake();
    $launcher = Mockery::mock(DetachedProcessLauncher::class);
    $launcher->shouldReceive('launch')
        ->once()
        ->withArgs(fn (array $command): bool => deploymentCommandContains($command, 'blb:software:update')
            && deploymentCommandContains($command, 'platform'))
        ->andReturnTrue();
    app()->instance(DetachedProcessLauncher::class, $launcher);

    try {
        Livewire::test(Index::class)
            ->call('updateRepo', 'platform')
            ->assertDispatched('run-finished', status: 'pending', refresh: false)
            ->assertHasNoErrors();

        $run = app(DeploymentRunHistory::class)->lastDeploymentRun();

        expect($run)->toBeArray()
            ->and($run['status'])->toBe('pending')
            ->and($run['summary'])->toContain(DEPLOYMENT_UPDATE_SCHEDULED_MESSAGE)
            ->and(app(SoftwareUpdateLauncher::class)->inProgress())->toBeTrue()
            ->and(app()->isDownForMaintenance())->toBeFalse();

        Process::assertNotRan(fn ($process): bool => gitCommandWithoutConfig($process->command) === ['git', 'pull', DEPLOYMENT_UPDATE_FF_ONLY]);
        Http::assertNothingSent();
    } finally {
        Cache::lock(SoftwareUpdateLauncher::LOCK_KEY)->forceRelease();
        Artisan::call('up');
    }
});

test('detached update command owns cleanup and records a terminal result', function (): void {
    $runId = 'deployment-command-test';
    $history = beginDeploymentCommandRun($runId);
    expectDeploymentCommandMaintenance($runId, reloadSucceeded: true);

    $deployment = Mockery::mock(DeploymentService::class);
    $deployment->shouldReceive('update')
        ->once()
        ->withArgs(function (array $keys, callable $progress, callable $afterReload): bool {
            $progress(DEPLOYMENT_UPDATE_PULLING_PLATFORM);
            $afterReload(true);

            return $keys === ['platform'];
        })
        ->andReturn([DEPLOYMENT_UPDATE_COMPLETE]);
    app()->instance(DeploymentService::class, $deployment);

    expect(Artisan::call('blb:software:update', [
        'keys' => ['platform'],
        '--run-id' => $runId,
    ]))->toBe(0)
        ->and(app(SoftwareUpdateLauncher::class)->inProgress())->toBeFalse();

    $run = $history->lastDeploymentRun();
    expect($run)->toMatchArray([
        'status' => 'success',
        'summary' => DEPLOYMENT_UPDATE_COMPLETE,
        'log' => [DEPLOYMENT_UPDATE_COMPLETE],
    ]);
});

test('a failed worker reload keeps the deployment in maintenance for manual recovery', function (): void {
    // The reload clears compiled-view/opcache caches before restarting the pool.
    // If the restart fails, the old workers are still live against a cleared view
    // cache — reopening the site would render the freshly pulled templates against
    // the old component code (the mixed-version window this command prevents), so
    // the run stays in maintenance and the operator brings the site back manually.
    $runId = 'deployment-command-reload-failed';
    $history = beginDeploymentCommandRun($runId);
    expectDeploymentCommandMaintenance($runId, reloadSucceeded: false);

    $deployment = Mockery::mock(DeploymentService::class);
    $deployment->shouldReceive('update')
        ->once()
        ->withArgs(function (array $keys, callable $progress, callable $afterReload): bool {
            $progress(DEPLOYMENT_UPDATE_PULLING_PLATFORM);
            $progress('Warning: web workers were not reloaded because the FrankenPHP admin API could not be reached.');
            $afterReload(false);

            return $keys === ['platform'];
        })
        ->andReturn(['Warning: web workers were not reloaded.']);
    app()->instance(DeploymentService::class, $deployment);

    expect(Artisan::call('blb:software:update', [
        'keys' => ['platform'],
        '--run-id' => $runId,
    ]))->toBe(0)
        ->and(app(SoftwareUpdateLauncher::class)->inProgress())->toBeFalse();

    $run = $history->lastDeploymentRun();
    expect($run)->toMatchArray([
        'status' => 'warning',
        'summary' => 'Warning: web workers were not reloaded.',
    ]);
});

test('maintenance actions are fenced while a detached update owns the execution lock', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);
    $runId = 'active-maintenance-fence';
    app(DeploymentRunHistory::class)->beginDeploymentRun($runId, ['platform'], 'Scheduled.');
    Cache::lock(SoftwareUpdateLauncher::LOCK_KEY, 3600, $runId)->get();
    fakeDeploymentUpdateProcesses();

    try {
        Livewire::test(Index::class)
            ->call('rebuildAssets')
            ->assertDispatched('run-finished', status: 'warning', refresh: false)
            ->assertHasNoErrors();

        Process::assertNotRan(fn ($process): bool => $process->command === ['bun', 'run', 'build']);
        expect(app(DeploymentRunHistory::class)->lastDeploymentRun())->toMatchArray([
            'status' => 'pending',
            'summary' => 'Scheduled.',
        ]);
    } finally {
        Cache::lock(SoftwareUpdateLauncher::LOCK_KEY)->forceRelease();
    }
});

test('an update cannot launch while a maintenance action holds the execution lock', function (): void {
    $detached = Mockery::mock(DetachedProcessLauncher::class);
    $detached->shouldNotReceive('launch');
    app()->instance(DetachedProcessLauncher::class, $detached);
    $launcher = app(SoftwareUpdateLauncher::class);
    $lock = $launcher->maintenanceActionLock();

    expect($lock->get())->toBeTrue();

    try {
        expect($launcher->launch(['platform']))
            ->toBe(['Warning: another software update is already running.']);
    } finally {
        $lock->release();
    }
});

test('deployment page shows the last frankenphp reload', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);
    fakeDeploymentUpdateProcesses();
    fakeDeploymentUpdateHttp();

    app(DeploymentService::class)->reload();

    Livewire::test(Index::class)
        ->assertSee('FrankenPHP workers')
        ->assertSee(DEPLOYMENT_UPDATE_LAST_RUN_LABEL)
        ->assertSee('Workers reloaded')
        ->assertSee(DEPLOYMENT_UPDATE_RELOADED);
});

test('deployment page allows retry when a reload state is stale', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);
    fakeDeploymentUpdateProcesses();
    Http::fake();

    app(SettingsService::class)->set('system.update.frankenphp.reload_state', [
        'attempted_at' => now()->subMinutes(6)->utc()->toIso8601String(),
        'status' => 'running',
        'message' => 'Runtime reload is running.',
        'admin_url' => null,
    ]);

    $component = Livewire::test(Index::class)
        ->assertSee('Reload stalled')
        ->assertSee('Retry reload');

    $html = $component->html();
    $retryLabelPosition = strpos($html, 'Retry reload');
    $buttonStartPosition = strrpos(substr($html, 0, $retryLabelPosition), '<button');
    $buttonEndPosition = strpos($html, '</button>', $buttonStartPosition);
    $reloadButton = substr($html, $buttonStartPosition, $buttonEndPosition - $buttonStartPosition);

    expect($reloadButton)
        ->toContain('wire:click="reloadOnly"')
        ->toContain('x-bind:disabled="running || refreshing || updateInProgress || maintenanceActive || reloadInProgress"')
        ->not->toContain('disabled="disabled"');
});

test('the previous run log persists at its rest location across page visits', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);
    fakeDeploymentUpdateProcesses();
    Http::fake([
        DEPLOYMENT_UPDATE_ADMIN_HOST.':*' => deploymentWorkerConfigResponse(),
        '*' => Http::response([], 200),
    ]);

    $log = Livewire::test(Index::class)
        ->call('reloadOnly')
        ->get('log');

    expect($log)->not->toBeEmpty();

    // A fresh visit still shows the last run at rest (it is session-persisted).
    // The pending run does NOT carry the recorded marker — only terminal runs
    // (success/warning/error) do. A pending marker would let the MutationObserver
    // fire detectRecordedRun prematurely during the updateAll morph, setting
    // markerSeen=true before the real terminal marker arrives and leaving the
    // "Running" badge stuck on a completed run.
    Livewire::test(Index::class)
        ->assertSet('log', $log)
        ->assertSee('run-finished.window', false)
        ->assertDontSee('data-run-outcome=', false)
        ->assertSee('window.location.reload()', false)
        ->assertSee('belimbing.deployment.run-log-after-refresh')
        ->assertSee('Run log saved. Reloading this page so commits and actions match the code on disk.')
        ->assertSee('Status refreshed. Current commits and actions now reflect the code on disk.')
        ->assertSee('dismissed: this.dismissed', false)
        ->assertSee('this.runLogOpen = ! payload.dismissed', false)
        ->assertSee('runLogOpen', false)
        ->assertSee('isFloating()', false)
        ->assertSee('h-72', false)
        ->assertSee('scrollToEnd', false);
});

test('the recorded-run marker is rendered only for terminal runs, not pending', function (): void {
    // Regression: the server used to render data-deployment-run-recorded whenever
    // $runStatus !== 'idle', which includes 'pending'. During an updateAll morph,
    // that pending marker let the MutationObserver fire detectRecordedRun
    // prematurely, setting markerSeen=true before the real terminal marker
    // arrived — so finishRun never fired and the "Running" badge stuck on a
    // completed run. The marker must appear only for terminal statuses, matching
    // the JS-side check in renderRunProgress.
    $this->actingAs(createAdminUser());
    $history = app(DeploymentRunHistory::class);

    // Pending run: no marker.
    $history->beginDeploymentRun('pending-run', ['platform'], DEPLOYMENT_UPDATE_SCHEDULED_MESSAGE);
    Livewire::test(Index::class)
        ->assertSee(DEPLOYMENT_UPDATE_SCHEDULED_MESSAGE)
        ->assertDontSee('data-run-outcome=', false);

    // Terminal run: marker present with the outcome.
    $history->finishDeploymentRun('pending-run', 'success', ['Update complete. Workers reloaded.']);
    Livewire::test(Index::class)
        ->assertSee('data-deployment-run-recorded="true"', false)
        ->assertSee('data-run-outcome="success"', false);
});

test('manual frontend rebuild installs with the lockfile package manager and builds assets', function (): void {
    Process::fake();

    $log = app(DeploymentService::class)->rebuildAssets();

    expect($log)->toContain('Frontend dependencies installed.')
        ->and($log)->toContain(DEPLOYMENT_UPDATE_FRONTEND_BUILT);

    Process::assertRan(fn ($process): bool => array_slice($process->command, 0, 3) === ['bun', 'install', '--frozen-lockfile']);
    Process::assertRan(fn ($process): bool => $process->command === ['bun', 'run', 'build']);
});

test('maintenance actions rebuild from the component and record the run', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);
    fakeDeploymentUpdateProcesses();
    fakeDeploymentUpdateHttp();

    Livewire::test(Index::class)
        ->assertSee('Maintenance')
        ->assertSee('Install PHP dependencies')
        ->assertSee('Build frontend assets') // command shown in mono beside the heading, so no tool suffix on the button
        ->assertSee('No composer install has been recorded yet.')
        ->assertSee('No frontend build has been recorded yet.')
        ->call('rebuildAssets')
        ->assertHasNoErrors()
        ->call('rebuildPhp')
        ->assertHasNoErrors();

    Process::assertRan(fn ($process): bool => $process->command === ['bun', 'run', 'build']);
    Process::assertRan(fn ($process): bool => in_array('install', $process->command, true));

    // Both runs leave a durable last-run record (like the FrankenPHP reload), so any
    // admin can later see whether vendor/ and public/build are current and healthy.
    $composerRun = app(DeploymentRunHistory::class)->lastComposerRun();
    $frontendRun = app(DeploymentRunHistory::class)->lastFrontendRun();

    expect($composerRun)->toBeArray()
        ->and($composerRun['ok'])->toBeTrue()
        ->and($composerRun['message'])->toBe('PHP dependencies installed.')
        ->and($frontendRun)->toBeArray()
        ->and($frontendRun['ok'])->toBeTrue()
        ->and($frontendRun['pm'])->toBe('bun')
        ->and($frontendRun['message'])->toBe(DEPLOYMENT_UPDATE_FRONTEND_BUILT);
});

test('a failed frontend build records a needs-attention last run', function (): void {
    Process::fake(fn ($process) => $process->command === ['bun', 'run', 'build']
        ? Process::result(errorOutput: 'bun: command not found', exitCode: 127)
        : Process::result());

    app(DeploymentService::class)->rebuildAssets();

    $frontendRun = app(DeploymentRunHistory::class)->lastFrontendRun();

    expect($frontendRun)->toBeArray()
        ->and($frontendRun['ok'])->toBeFalse()
        ->and($frontendRun['message'])->toContain('Frontend asset build failed');
});

test('updating the platform pulls, refreshes runtime artifacts, migrates, and reloads', function (): void {
    fakeDeploymentUpdateProcesses();
    fakeDeploymentUpdateHttp();

    $log = app(DeploymentService::class)->update(['platform']);

    expect($log)->toContain('Building frontend assets…')
        ->and($log)->toContain(DEPLOYMENT_UPDATE_FRONTEND_BUILT)
        ->and($log)->toContain(DEPLOYMENT_UPDATE_VERIFIED_PLATFORM)
        ->and($log)->toContain(DEPLOYMENT_UPDATE_COMPLETE);

    Process::assertRan(fn ($process): bool => gitCommandWithoutConfig($process->command) === ['git', 'pull', DEPLOYMENT_UPDATE_FF_ONLY]);
    Process::assertRan(fn ($process): bool => in_array('dump-autoload', $process->command, true));
    Process::assertRan(fn ($process): bool => $process->command === ['bun', 'run', 'build']);
    Process::assertRan(fn ($process): bool => deploymentCommandContains($process->command, 'migrate')
        && deploymentCommandContains($process->command, '--force')
        && deploymentCommandContains($process->command, '--no-interaction'));
});

test('a failed frontend rebuild halts the deployment before migrations and reload', function (): void {
    Process::fake(function ($process) {
        if (array_slice($process->command, 0, 3) === ['bun', 'install', '--frozen-lockfile']) {
            return Process::result(errorOutput: "'bun' is not recognized as an internal or external command", exitCode: 1);
        }

        return fakeDeploymentUpdateGitResult($process->command) ?? Process::result();
    });
    Http::fake();

    $log = app(DeploymentService::class)->update(['platform']);

    expect($log)->toContain("Frontend dependency install failed: 'bun' is not recognized as an internal or external command")
        ->and($log)->toContain('FAILED: frontend assets did not build; deployment halted before migrations and reload.')
        ->and($log)->not->toContain('Running migrations…')
        ->and($log)->not->toContain('FAILED: database migrations did not complete; deployment halted before reload.');

    Http::assertNothingSent();
});

test('a run records a durable deployment last-run with its time and outcome', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);
    fakeDeploymentUpdateProcesses();
    fakeDeploymentUpdateHttp();

    Livewire::test(Index::class)
        ->call('reloadOnly')
        ->assertHasNoErrors();

    $run = app(DeploymentRunHistory::class)->lastDeploymentRun();

    expect($run)->toBeArray()
        ->and($run['status'])->toBe('pending')
        ->and($run['attempted_at'])->toBeString()
        ->and($run['log'])->not->toBeEmpty();
});

test('the run box shows the last run, with its time, on a fresh visit', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);
    fakeDeploymentUpdateProcesses();
    fakeDeploymentUpdateHttp();

    // A durable record stands in for a run from an earlier session (the session log is gone).
    app(DeploymentRunHistory::class)->rememberDeploymentRun(
        [DEPLOYMENT_UPDATE_PULLING_PLATFORM, DEPLOYMENT_UPDATE_COMPLETE],
        'success',
    );

    Livewire::test(Index::class)
        ->assertSee(DEPLOYMENT_UPDATE_LAST_RUN_LABEL)
        ->assertSee(DEPLOYMENT_UPDATE_COMPLETE);
});

test('the run card shows an empty state before any run has happened', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);
    fakeDeploymentUpdateProcesses();
    Http::fake();

    Livewire::test(Index::class)
        ->assertSee(DEPLOYMENT_UPDATE_LAST_RUN_LABEL)
        ->assertSee('No update has run yet.');
});

test('the update console stays reachable during maintenance and can bring the site back online', function (): void {
    $user = createAdminUser();
    fakeDeploymentUpdateProcesses();
    Http::fake();

    Artisan::call('down');

    try {
        $this->actingAs($user)
            ->get(route('admin.system.software.updates.index'))
            ->assertOk()
            ->assertSee('The site is in maintenance mode.');

        $this->actingAs($user)
            ->post(route('admin.system.software.online'))
            ->assertRedirect(route('admin.system.software.updates.index'))
            ->assertSessionHas('status');

        expect(app()->isDownForMaintenance())->toBeFalse();
    } finally {
        Artisan::call('up');
    }
});

test('the updates page suppresses wire:init during maintenance so Livewire 503s do not flash the operator', function (): void {
    // wire:init fires a Livewire AJAX call on page load. The Livewire endpoint
    // (livewire/*) is NOT maintenance-exempt, so during an update the call 503s
    // and Livewire shows the 503 page in an error modal — the primary source of
    // the 500/503 flashing the operator saw. Suppress the attribute entirely
    // while maintenance is active; the remote status check is stale mid-update
    // anyway, and the page reloads with wire:init once maintenance lifts.
    $user = createAdminUser();
    fakeDeploymentUpdateProcesses();
    Http::fake();

    Artisan::call('down');

    try {
        $response = $this->actingAs($user)
            ->get(route('admin.system.software.updates.index'));

        $response->assertOk()
            ->assertSee('The site is in maintenance mode.')
            ->assertDontSee('wire:init="loadLatestStatus"', false);

        // The "Checking latest…" spinner would spin forever without wire:init;
        // it must be replaced by a plain em-dash while maintenance is active.
        $body = $response->getContent();
        expect($body)->not->toContain('Checking latest…')
            ->and($body)->toContain('maintenanceActive');
    } finally {
        Artisan::call('up');
    }
});

test('behind is a Livewire public property so Alpine can react to it via $wire after wire:init loads remote status', function (): void {
    // "Update all" was stuck disabled after remote status loaded because
    // updateAllUnavailable was initialized from @js(! $behind) — a one-time
    // snapshot that Livewire morph never re-evaluates. The fix exposes $behind
    // as a public property synced via $wire, so x-bind:disabled="! $wire.behind"
    // re-evaluates reactively when the property changes after loadLatestStatus.
    $user = createAdminUser();
    // Remote HEAD differs from the local SHA so the bundle is behind after
    // loadLatestStatus triggers the remote status fetch.
    fakeDeploymentUpdateProcesses(remoteSha: DEPLOYMENT_UPDATE_REMOTE_SHA);
    Http::fake();

    // Initial render: localStatus has no remote data, $behind is false.
    $component = Livewire::test(Index::class);
    expect($component->get('behind'))->toBeFalse();

    // After loadLatestStatus: remote status loads, $behind becomes true.
    $component->call('loadLatestStatus')
        ->assertSet('behind', true);

    // The blade uses $wire.behind in x-bind:disabled, not @js(! $behind) —
    // @js() is a stale snapshot, $wire is Livewire's reactive proxy.
    $this->actingAs($user)
        ->get(route('admin.system.software.updates.index'))
        ->assertOk()
        ->assertSee('$wire.behind')
        ->assertDontSee('updateAllUnavailable');
});

test('maintenance cleanup is fenced to the detached update that owns it', function (): void {
    $maintenance = app(DeploymentMaintenanceGuard::class);
    $writeLease = new ReflectionMethod($maintenance, 'writeLease');

    try {
        $writeLease->invoke($maintenance, 'owned-update', true);
        $maintenance->enter('owned-update');

        expect($maintenance->ownsMaintenance('owned-update'))->toBeTrue();

        $maintenance->leave('different-update');
        expect(app()->isDownForMaintenance())->toBeTrue();

        $maintenance->leave('owned-update');
        expect(app()->isDownForMaintenance())->toBeFalse();
    } finally {
        $maintenance->disarm('owned-update');
        Artisan::call('up');
    }
});

test('manual recovery cannot expose an update with a live maintenance lease', function (): void {
    $user = createAdminUser();
    $maintenance = app(DeploymentMaintenanceGuard::class);
    $writeLease = new ReflectionMethod($maintenance, 'writeLease');

    try {
        $writeLease->invoke($maintenance, 'active-update', true);
        $maintenance->enter('active-update');

        $this->actingAs($user)
            ->post(route('admin.system.software.online'))
            ->assertRedirect(route('admin.system.software.updates.index'))
            ->assertSessionHas('error');

        expect($maintenance->ownsMaintenance('active-update'))->toBeTrue();
    } finally {
        $maintenance->disarm('active-update');
        Artisan::call('up');
    }
});

test('an expired watchdog lease recovers only its maintenance run', function (): void {
    $runId = 'expired-update';
    $maintenance = app(DeploymentMaintenanceGuard::class);
    $history = app(DeploymentRunHistory::class);
    $history->beginDeploymentRun($runId, ['platform'], 'Scheduled.');
    $writeLease = new ReflectionMethod($maintenance, 'writeLease');

    try {
        $writeLease->invoke($maintenance, $runId, true);
        $maintenance->enter($runId);
        $writeLease->invoke($maintenance, $runId, true, time() - 1);

        expect($maintenance->recoverExpired($runId, $history))->toBeTrue()
            ->and(app()->isDownForMaintenance())->toBeFalse()
            ->and($maintenance->leaseExists($runId))->toBeFalse()
            ->and($history->lastDeploymentRun())->toMatchArray([
                'status' => 'error',
                'summary' => 'FAILED: the update process stopped responding; automatic recovery brought Belimbing back online.',
            ]);
    } finally {
        $maintenance->disarm($runId);
        Artisan::call('up');
    }
});

test('the updates page renders synchronously so recovery remains available during maintenance', function (): void {
    $user = createAdminUser();
    Process::fake();

    $this->actingAs($user)
        ->get(route('admin.system.software.updates.index'))
        ->assertOk()
        ->assertSee(__('Updates'))
        ->assertDontSee(__('Loading page…'));
});

test('update reports reload problems as warnings instead of clean completion', function (): void {
    withDeploymentAdminEnv(DEPLOYMENT_UPDATE_ADMIN_HOST, '2019', function (): void {
        fakeDeploymentUpdateProcesses();
        fakeDeploymentUpdateHttp(reloadOk: false);

        $log = app(DeploymentService::class)->update(['platform']);

        expect($log)->toContain('Warning: web workers were not reloaded because the FrankenPHP admin API at http://127.0.0.1:2019/config/apps/frankenphp did not expose worker config. Check CADDY_SERVER_ADMIN_HOST and CADDY_SERVER_ADMIN_PORT.')
            ->and($log)->toContain(DEPLOYMENT_UPDATE_VERIFIED_PLATFORM)
            ->and($log)->toContain('Update finished with warnings. Pull, build, and migration steps completed, but one or more follow-up checks need attention.')
            ->and($log)->not->toContain(DEPLOYMENT_UPDATE_COMPLETE);
    });
});

test('the worker reload reads its admin port from the octane server-state file', function (): void {
    // No env override → the port must come from octane's recorded state, not the
    // stock Caddy default of 2019 (which is the wrong port for our deployments).
    withDeploymentOctaneState(
        deploymentOctaneState(),
        fn () => expectDeploymentReloadUsesAdminEndpoint(DEPLOYMENT_UPDATE_ADMIN_BASE_URL)
    );
});

test('the worker reload prefers the local octane listener for application health checks', function (): void {
    withDeploymentOctaneState(
        deploymentOctaneState([
            'host' => DEPLOYMENT_UPDATE_ADMIN_HOST,
            'port' => 8100,
        ]),
        function (): void {
            fakeDeploymentUpdateProcesses();

            $baseUrl = DEPLOYMENT_UPDATE_ADMIN_BASE_URL;
            $localHealthUrl = 'http://127.0.0.1:8100/up';
            $appHealthUrl = rtrim((string) config('app.url'), '/').'/up';

            Http::fake([
                deploymentAdminConfigUrl($baseUrl) => deploymentWorkerConfigResponse(),
                deploymentAdminRestartUrl($baseUrl) => Http::response('', 200),
                $localHealthUrl => Http::response('', 200),
                $appHealthUrl => Http::response('', 503),
                '*' => Http::response('', 500),
            ]);

            $log = app(DeploymentService::class)->reload();

            expect($log)->toContain(DEPLOYMENT_UPDATE_RELOADED);
            Http::assertSent(fn ($request): bool => $request->url() === $localHealthUrl);
            Http::assertNotSent(fn ($request): bool => $request->url() === $appHealthUrl);
        }
    );
});

test('the worker reload does not guess the Windows launcher admin port', function (): void {
    withDeploymentOctaneState(null, function (): void {
        fakeDeploymentUpdateProcesses();
        Http::fake([
            deploymentAdminConfigUrl(str_replace(':2643', ':2019', DEPLOYMENT_UPDATE_ADMIN_BASE_URL)) => Http::response([], 200),
            '*' => Http::response('', 500),
        ]);

        app(DeploymentService::class)->reload();

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), ':2020/'));
    });
});

test('the worker reload retries once when the FrankenPHP admin API times out', function (): void {
    withDeploymentAdminEnv(DEPLOYMENT_UPDATE_ADMIN_HOST, '2643', function (): void {
        fakeDeploymentUpdateProcesses();

        $getAttempts = 0;

        Http::fake(function ($request) use (&$getAttempts) {
            return fakeDeploymentTimedOutAdminApiResponse($request->url(), $request->method(), $getAttempts);
        });

        $log = app(DeploymentService::class)->reload();

        expect($log)->toContain(DEPLOYMENT_UPDATE_RELOADED)
            ->and($getAttempts)->toBe(2);
    });
});

test('the worker reload records a warning when application health does not recover', function (): void {
    withDeploymentAdminEnv(DEPLOYMENT_UPDATE_ADMIN_HOST, '2643', function (): void {
        fakeDeploymentUpdateProcesses();

        $baseUrl = DEPLOYMENT_UPDATE_ADMIN_BASE_URL;
        $healthUrl = rtrim((string) config('app.url'), '/').'/up';

        Http::fake([
            deploymentAdminConfigUrl($baseUrl) => deploymentWorkerConfigResponse(),
            deploymentAdminRestartUrl($baseUrl) => Http::response('', 200),
            $healthUrl => Http::response('', 503),
            '*' => Http::response('', 500),
        ]);

        $log = app(DeploymentService::class)->reload();
        $stored = app(DeploymentRunHistory::class)->lastReload();

        expect($log)->toContain("Warning: web workers restart was accepted, but the application health check did not recover: {$healthUrl} returned HTTP 503")
            ->and($stored)->toMatchArray([
                'ok' => false,
                'message' => "Warning: web workers restart was accepted, but the application health check did not recover: {$healthUrl} returned HTTP 503",
            ]);
    });
});

test('a diverged bundle reports an actionable message instead of raw git hints', function (): void {
    Process::fake(function ($process) {
        if (in_array(DEPLOYMENT_UPDATE_FF_ONLY, $process->command, true)) {
            return Process::result(
                errorOutput: "From https://github.com/kiatng/blb-sbg\n   024bd2e..d45cbe4  main -> origin/main\n".
                    "hint: Diverging branches can't be fast-forwarded, you need to either:\nhint:\n".
                    'fatal: Not possible to fast-forward, aborting.',
                exitCode: 128,
            );
        }

        return Process::result('https://github.com/kiatng/blb-sbg.git');
    });

    $message = app(DistributionBundleRepository::class)->pull(['label' => 'blb-sbg', 'path' => '/srv/blb-sbg']);

    expect($message)
        ->toContain('blb-sbg has diverged from its remote')
        ->toContain('git -C /srv/blb-sbg log --oneline @{u}..HEAD')
        ->not->toContain('hint:')
        ->not->toContain('fatal:');
});

function fakeBundleGit(string $porcelain, string $leftRightCount): Closure
{
    return function ($process) use ($porcelain, $leftRightCount) {
        $command = gitCommandWithoutConfig($process->command);
        [$behind, $ahead] = array_map('intval', explode("\t", $leftRightCount));
        $branchStatus = '## main...origin/main'.match (true) {
            $ahead > 0 && $behind > 0 => " [ahead {$ahead}, behind {$behind}]",
            $ahead > 0 => " [ahead {$ahead}]",
            $behind > 0 => " [behind {$behind}]",
            default => '',
        };
        $statusOutput = $porcelain !== '' ? $branchStatus."\n".$porcelain : $branchStatus;

        return match (true) {
            $command === ['git', 'status', '--porcelain=v1', '--branch'] => Process::result($statusOutput),
            $command === ['git', 'status', '--porcelain'] => Process::result($porcelain),
            in_array('rev-list', $process->command, true) => Process::result($leftRightCount),
            $command === ['git', 'remote', 'get-url', 'origin'] => Process::result(DEPLOYMENT_UPDATE_REMOTE),
            $command === ['git', 'rev-parse', DEPLOYMENT_UPDATE_BRANCH_ARG, 'HEAD'] => Process::result('main'),
            in_array('ls-remote', $process->command, true) => Process::result(DEPLOYMENT_UPDATE_SHA."\trefs/heads/main"),
            in_array('log', $process->command, true), in_array('show', $process->command, true) => Process::result(DEPLOYMENT_UPDATE_SHA."\x1f".now()->toIso8601String().DEPLOYMENT_UPDATE_COMMIT_TRAILER),
            default => Process::result(),
        };
    };
}

test('bundle status surfaces a dirty and diverged working tree', function (): void {
    // git status --porcelain=v1 --branch reports "[ahead N, behind N]" on the branch header.
    Process::fake(fakeBundleGit(" M a.php\n?? b.php\n D c.php", "4\t2"));

    $platform = collect(app(DistributionBundleRepository::class)->status())->firstWhere('key', 'platform');

    expect($platform['working_tree']['dirty'])->toBe(3)
        ->and($platform['working_tree']['ahead'])->toBe(2)
        ->and($platform['working_tree']['behind'])->toBe(4);
});

test('a clean bundle reports a clean working tree', function (): void {
    Process::fake(fakeBundleGit('', "0\t0"));

    $platform = collect(app(DistributionBundleRepository::class)->status())->firstWhere('key', 'platform');

    expect($platform['working_tree'])->toBe(['dirty' => 0, 'ahead' => 0, 'behind' => 0]);
});

test('the deployment page flags a bundle with uncommitted and unpushed changes', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);
    Process::fake(fakeBundleGit(" M ibp/Database/Migrations/x.php\n?? y.php", "0\t2"));
    Http::fake();

    Livewire::test(Index::class)
        ->assertSee('2 uncommitted changes')
        ->assertSee('2 unpushed commits');
});

test('a failed migration halts the deployment before reloading workers', function (): void {
    Process::fake(function ($process) {
        if (deploymentCommandContains($process->command, 'migrate')) {
            return Process::result(
                errorOutput: 'Pending incubating schema cannot be migrated outside local/testing without a local approval',
                exitCode: 1,
            );
        }

        return fakeDeploymentUpdateGitResult($process->command) ?? Process::result();
    });
    fakeDeploymentUpdateHttp();

    $log = app(DeploymentService::class)->update(['platform']);

    expect($log)->toContain('FAILED: database migrations did not complete; deployment halted before reload.')
        ->and(collect($log)->contains(fn (string $line): bool => str_contains($line, 'Pending incubating schema cannot be migrated outside local/testing without a local approval')))->toBeTrue()
        ->and($log)->not->toContain(DEPLOYMENT_UPDATE_COMPLETE);

    // Workers were never reloaded because the fresh migration process failed.
    Http::assertNothingSent();
});
