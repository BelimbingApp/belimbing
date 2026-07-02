<?php

use App\Base\Settings\Contracts\SettingsService;
use App\Base\Software\Livewire\Deployment\Index;
use App\Base\Software\Services\DeploymentRunHistory;
use App\Base\Software\Services\DeploymentService;
use App\Base\Software\Services\DistributionBundleRepository;
use App\Base\Software\Services\FrankenPhpDomainRuntimeReloader;
use App\Base\Support\PhpCli;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

const DEPLOYMENT_UPDATE_SHA = 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeef';
const DEPLOYMENT_UPDATE_COMMIT_TRAILER = "\x1fCI\x1fCurrent";
const DEPLOYMENT_UPDATE_FRONTEND_BUILT = 'Frontend assets built.';
const DEPLOYMENT_UPDATE_LAST_RUN_LABEL = 'Last run';
const DEPLOYMENT_UPDATE_VERIFIED_PLATFORM = 'Verified: Belimbing (platform) is at deadbee and matches main.';
const DEPLOYMENT_UPDATE_COMPLETE = 'Update complete. Selected Distribution Bundles are up to date and workers were reloaded.';
const DEPLOYMENT_UPDATE_REMOTE = 'https://github.com/BelimbingApp/belimbing.git';
const DEPLOYMENT_UPDATE_BRANCH_ARG = '--abbrev-ref';
const DEPLOYMENT_UPDATE_LOG_FORMAT = '--format=%H%x1f%cI%x1f%an%x1f%s';
const DEPLOYMENT_UPDATE_FF_ONLY = '--ff-only';
const DEPLOYMENT_UPDATE_RELOADED = 'Web workers reloaded.';

final class DeploymentUpdateGitLaunchException extends RuntimeException {}

function fakeDeploymentUpdateProcesses(string $sha = DEPLOYMENT_UPDATE_SHA, ?string $remoteError = null): void
{
    Process::fake(function ($process) use ($sha, $remoteError) {
        return fakeDeploymentUpdateGitResult($process->command, $sha, $remoteError) ?? Process::result();
    });
}

function fakeDeploymentUpdateGitResult(array $command, string $sha = DEPLOYMENT_UPDATE_SHA, ?string $remoteError = null): mixed
{
    return match (gitCommandWithoutConfig($command)) {
        ['git', 'remote', 'get-url', 'origin'] => Process::result(DEPLOYMENT_UPDATE_REMOTE),
        ['git', 'rev-parse', DEPLOYMENT_UPDATE_BRANCH_ARG, 'HEAD'] => Process::result('main'),
        ['git', 'log', '-1', DEPLOYMENT_UPDATE_LOG_FORMAT] => Process::result($sha."\x1f".now()->toIso8601String().DEPLOYMENT_UPDATE_COMMIT_TRAILER),
        ['git', 'ls-remote', '--exit-code', 'origin', 'refs/heads/main'] => $remoteError === null
            ? Process::result($sha."\trefs/heads/main")
            : Process::result(errorOutput: $remoteError, exitCode: 1),
        ['git', 'show', '-s', DEPLOYMENT_UPDATE_LOG_FORMAT, $sha] => Process::result($sha."\x1f".now()->toIso8601String().DEPLOYMENT_UPDATE_COMMIT_TRAILER),
        ['git', 'pull', DEPLOYMENT_UPDATE_FF_ONLY] => Process::result('Already up to date.'),
        default => null,
    };
}

function fakeDeploymentUpdateHttp(bool $reloadOk = true): void
{
    Http::fake([
        '127.0.0.1:*' => $reloadOk
            ? Http::response(['workers' => [['file_name' => public_path('frankenphp-worker.php')]]], 200)
            : Http::response('', 500),
        '*' => Http::response([], 200),
    ]);
}

function deploymentCommandContains(array $command, string $needle): bool
{
    return collect($command)->contains(fn (string $part): bool => str_contains($part, $needle));
}

function withDeploymentOctaneState(?array $state, Closure $callback): void
{
    putenv('CADDY_SERVER_ADMIN_HOST');
    putenv('CADDY_SERVER_ADMIN_PORT');

    $statePath = storage_path('logs/octane-server-state.json');
    $backup = is_file($statePath) ? file_get_contents($statePath) : null;

    $state === null
        ? @unlink($statePath)
        : file_put_contents($statePath, json_encode($state));

    try {
        $callback();
    } finally {
        $backup === null ? @unlink($statePath) : file_put_contents($statePath, $backup);
    }
}

function expectDeploymentReloadUsesAdminEndpoint(string $baseUrl): void
{
    fakeDeploymentUpdateProcesses();
    Http::fake([
        $baseUrl.'/config/apps/frankenphp' => Http::response(['workers' => [['file_name' => public_path('frankenphp-worker.php')]]], 200),
        $baseUrl.'/frankenphp/workers/restart' => Http::response('', 200),
        '*' => Http::response('', 500),
    ]);

    $log = app(DeploymentService::class)->reload();

    expect($log)->toContain(DEPLOYMENT_UPDATE_RELOADED);
    Http::assertSent(fn ($request): bool => $request->url() === $baseUrl.'/frankenphp/workers/restart');
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), ':2019/'));
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
        ->assertSee('Reload FrankenPHP')
        ->assertSee('Streaming live output. Keep this window open until the status refresh starts.')
        ->assertDontSee('Keep this tab open')
        ->assertSee('does not pull code, install dependencies, build assets, or run migrations')
        ->assertDontSee('Code repositories');

    Http::assertSentCount(0);
});

test('failed remote checks name the repos instead of assuming they are private', function (): void {
    $user = createAdminUser();
    fakeDeploymentUpdateProcesses(remoteError: 'fatal: unable to access repository');
    Http::fake();

    $this->actingAs($user)
        ->get(route('admin.system.software.updates.index'))
        ->assertOk()
        ->assertSee('Could not check latest commits for these Distribution Bundles: BelimbingApp/belimbing')
        ->assertSee('Public repositories do not need a token')
        ->assertSee('Could not read latest commit for BelimbingApp/belimbing@main via git ls-remote (fatal: unable to access repository)')
        ->assertDontSee('A private repository could not be checked');

    Http::assertSentCount(0);
});

test('deployment page reports when git cannot be launched', function (): void {
    $user = createAdminUser();
    Process::fake(fn () => throw new DeploymentUpdateGitLaunchException('git executable was not found'));
    Http::fake();

    $this->actingAs($user)
        ->get(route('admin.system.software.updates.index'))
        ->assertOk()
        ->assertSee('Could not read Git origin remote')
        ->assertSee('Could not run git')
        ->assertSee('git executable was not found')
        ->assertDontSee('No GitHub origin remote');

    Http::assertSentCount(0);
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
            ->assertDispatched('run-finished', status: 'success', refresh: true)
            ->assertHasNoErrors();

        expect($component->get('log'))->toBe(['Runtime reload scheduled in the background.'])
            ->and(Cache::has(FrankenPhpDomainRuntimeReloader::PENDING_CACHE_KEY))->toBeTrue();

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
    fakeDeploymentUpdateProcesses();
    fakeDeploymentUpdateHttp();
    Cache::put(FrankenPhpDomainRuntimeReloader::PENDING_CACHE_KEY, now()->utc()->toIso8601String(), now()->addMinute());

    $status = Artisan::call('blb:domain-runtime:reload', ['--delay' => 0]);
    $stored = app(SettingsService::class)->get('system.update.frankenphp.last_reload');

    expect($status)->toBe(0)
        ->and($stored)->toBeArray()
        ->and($stored['ok'])->toBeTrue()
        ->and($stored['message'])->toBe(DEPLOYMENT_UPDATE_RELOADED)
        ->and(Cache::has(FrankenPhpDomainRuntimeReloader::PENDING_CACHE_KEY))->toBeFalse();

    Process::assertRan(fn ($process): bool => $process->command === PhpCli::current()->artisan(['about', '--only=environment']));
});

test('software update runtime reload command reloads workers after clearing runtime caches', function (): void {
    fakeDeploymentUpdateProcesses();
    fakeDeploymentUpdateHttp();
    Cache::put(FrankenPhpDomainRuntimeReloader::PENDING_CACHE_KEY, now()->utc()->toIso8601String(), now()->addMinute());

    $status = Artisan::call('blb:domain-runtime:reload', ['--delay' => 0, '--clear-runtime-caches' => true]);
    $stored = app(SettingsService::class)->get('system.update.frankenphp.last_reload');

    expect($status)->toBe(0)
        ->and($stored)->toBeArray()
        ->and($stored['ok'])->toBeTrue()
        ->and($stored['message'])->toBe(DEPLOYMENT_UPDATE_RELOADED)
        ->and(Cache::has(FrankenPhpDomainRuntimeReloader::PENDING_CACHE_KEY))->toBeFalse();

    Process::assertRan(fn ($process): bool => $process->command === PhpCli::current()->artisan(['about', '--only=environment']));
});

test('component updates record completion before scheduling the runtime reload', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);
    Cache::forget(FrankenPhpDomainRuntimeReloader::PENDING_CACHE_KEY);
    fakeDeploymentUpdateProcesses();
    Http::fake();

    try {
        Livewire::test(Index::class)
            ->call('updateRepo', 'platform')
            ->assertDispatched('run-finished', status: 'success', refresh: true)
            ->assertHasNoErrors();

        $run = app(DeploymentRunHistory::class)->lastDeploymentRun();

        expect($run)->toBeArray()
            ->and($run['status'])->toBe('success')
            ->and($run['summary'])->toBe('Runtime reload scheduled in the background.')
            ->and($run['log'])->toContain('Update complete. Selected Distribution Bundles are up to date; runtime reload still needs to run separately.')
            ->and($run['log'])->toContain('Runtime reload scheduled in the background.')
            ->and(Cache::has(FrankenPhpDomainRuntimeReloader::PENDING_CACHE_KEY))->toBeTrue();

        Process::assertRan(fn ($process): bool => deploymentCommandContains($process->command, 'blb:domain-runtime:reload')
            && deploymentCommandContains($process->command, '--clear-runtime-caches'));
        Http::assertNothingSent();
    } finally {
        Cache::forget(FrankenPhpDomainRuntimeReloader::PENDING_CACHE_KEY);
        Artisan::call('up');
    }
});

test('the worker reload probes the Windows launcher admin port before the stock Caddy port', function (): void {
    withDeploymentOctaneState(
        null,
        fn () => expectDeploymentReloadUsesAdminEndpoint('http://127.0.0.1:2020')
    );
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

test('the previous run log persists at its rest location across page visits', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);
    fakeDeploymentUpdateProcesses();
    Http::fake([
        '127.0.0.1:*' => Http::response(['workers' => [['file_name' => public_path('frankenphp-worker.php')]]], 200),
        '*' => Http::response([], 200),
    ]);

    $log = Livewire::test(Index::class)
        ->call('reloadOnly')
        ->get('log');

    expect($log)->not->toBeEmpty();

    // A fresh visit still shows the last run at rest (it is session-persisted).
    // The hidden completion marker is harmless because its Alpine detector ignores
    // markers unless the browser still believes a run is active.
    Livewire::test(Index::class)
        ->assertSet('log', $log)
        ->assertSee('run-finished.window', false)
        ->assertSee('data-deployment-run-recorded', false)
        ->assertSee('window.location.reload()', false)
        ->assertSee('belimbing.deployment.run-log-after-refresh')
        ->assertSee('Run log saved. Reloading this page so commits and actions match the code on disk.')
        ->assertSee('Status refreshed. Current commits and actions now reflect the code on disk.')
        ->assertSee('runLogOpen', false)
        ->assertSee('isFloating()', false)
        ->assertSee('h-72', false)
        ->assertSee('scrollToEnd', false);
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
    expect(app()->isDownForMaintenance())->toBeFalse();
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
        ->and($run['status'])->toBe('success')
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
        ['Pulling Belimbing (platform)…', DEPLOYMENT_UPDATE_COMPLETE],
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

test('update reports reload problems as warnings instead of clean completion', function (): void {
    putenv('CADDY_SERVER_ADMIN_HOST=127.0.0.1');
    putenv('CADDY_SERVER_ADMIN_PORT=2019');

    fakeDeploymentUpdateProcesses();
    fakeDeploymentUpdateHttp(reloadOk: false);

    try {
        $log = app(DeploymentService::class)->update(['platform']);

        expect($log)->toContain('Warning: web workers were not reloaded because the FrankenPHP admin API at http://127.0.0.1:2019/config/apps/frankenphp did not expose worker config. Check CADDY_SERVER_ADMIN_HOST and CADDY_SERVER_ADMIN_PORT.')
            ->and($log)->toContain(DEPLOYMENT_UPDATE_VERIFIED_PLATFORM)
            ->and($log)->toContain('Update finished with warnings. Pull, build, and migration steps completed, but one or more follow-up checks need attention.')
            ->and($log)->not->toContain(DEPLOYMENT_UPDATE_COMPLETE);
    } finally {
        putenv('CADDY_SERVER_ADMIN_HOST');
        putenv('CADDY_SERVER_ADMIN_PORT');
    }
});

test('the worker reload reads its admin port from the octane server-state file', function (): void {
    // No env override → the port must come from octane's recorded state, not the
    // stock Caddy default of 2019 (which is the wrong port for our deployments).
    withDeploymentOctaneState(
        ['state' => ['adminHost' => '127.0.0.1', 'adminPort' => 2643]],
        fn () => expectDeploymentReloadUsesAdminEndpoint('http://127.0.0.1:2643')
    );
});

test('the worker reload retries once when the FrankenPHP admin API times out', function (): void {
    putenv('CADDY_SERVER_ADMIN_HOST=127.0.0.1');
    putenv('CADDY_SERVER_ADMIN_PORT=2643');

    fakeDeploymentUpdateProcesses();

    $getAttempts = 0;

    try {
        Http::fake(function ($request) use (&$getAttempts) {
            $url = 'http://127.0.0.1:2643/config/apps/frankenphp';
            $restartUrl = 'http://127.0.0.1:2643/frankenphp/workers/restart';

            if (! in_array($request->url(), [$url, $restartUrl], true)) {
                return Http::response('', 500);
            }

            if ($request->method() === 'GET') {
                $getAttempts++;

                if ($getAttempts === 1) {
                    throw new ConnectionException(
                        'cURL error 28: Operation timed out after 10008 milliseconds with 0 bytes received for '.$url
                    );
                }

                return Http::response(['workers' => [['file_name' => public_path('frankenphp-worker.php')]]], 200);
            }

            return Http::response('', 200);
        });

        $log = app(DeploymentService::class)->reload();

        expect($log)->toContain(DEPLOYMENT_UPDATE_RELOADED)
            ->and($getAttempts)->toBe(2);
    } finally {
        putenv('CADDY_SERVER_ADMIN_HOST');
        putenv('CADDY_SERVER_ADMIN_PORT');
    }
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

        return match (true) {
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
    // git rev-list --left-right --count @{u}...HEAD => "<behind>\t<ahead>"
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
    fakeDeploymentUpdateProcesses();
    fakeDeploymentUpdateHttp();

    // An incubating migration makes `migrate` fail on a non-disposable database.
    writeIncubatingTestMigration(
        'extensions/test-vendor/deploy-guard/Database/Migrations',
        '2099_02_02_020202_create_deploy_guard_widgets_table.php',
        'deploy_guard_widgets',
    );
    app()['env'] = 'production';

    try {
        $log = app(DeploymentService::class)->update(['platform']);

        expect($log)->toContain('FAILED: database migrations did not complete; deployment halted before reload.')
            ->and(collect($log)->contains(fn (string $line): bool => str_contains($line, 'Pending incubating schema cannot be migrated outside local/testing without a local approval')))->toBeTrue()
            ->and($log)->not->toContain(DEPLOYMENT_UPDATE_COMPLETE);

        // Workers were never reloaded because the deploy halted at the migration step.
        Http::assertNothingSent();
        expect(app()->isDownForMaintenance())->toBeFalse();
    } finally {
        cleanupIncubatingTestMigration(
            'extensions/test-vendor/deploy-guard/Database/Migrations',
            '2099_02_02_020202_create_deploy_guard_widgets_table.php',
            'deploy_guard_widgets',
        );
    }
});
