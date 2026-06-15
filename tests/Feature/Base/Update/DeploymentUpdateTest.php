<?php

use App\Base\Settings\Contracts\SettingsService;
use App\Base\Update\Livewire\Deployment\Index;
use App\Base\Update\Services\DeploymentService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

const DEPLOYMENT_UPDATE_SHA = 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeef';

function fakeDeploymentUpdateProcesses(string $sha = DEPLOYMENT_UPDATE_SHA, ?string $remoteError = null): void
{
    Process::fake(function ($process) use ($sha, $remoteError) {
        return match ($process->command) {
            ['git', 'remote', 'get-url', 'origin'] => Process::result('https://github.com/BelimbingApp/belimbing.git'),
            ['git', 'rev-parse', '--abbrev-ref', 'HEAD'] => Process::result('main'),
            ['git', 'log', '-1', '--format=%H%x1f%cI%x1f%an%x1f%s'] => Process::result($sha."\x1f".now()->toIso8601String()."\x1fCI\x1fCurrent"),
            ['git', 'ls-remote', '--exit-code', 'origin', 'refs/heads/main'] => $remoteError === null
                ? Process::result($sha."\trefs/heads/main")
                : Process::result(errorOutput: $remoteError, exitCode: 1),
            ['git', 'show', '-s', '--format=%H%x1f%cI%x1f%an%x1f%s', $sha] => Process::result($sha."\x1f".now()->toIso8601String()."\x1fCI\x1fCurrent"),
            default => Process::result(),
        };
    });
}

function fakeDeploymentUpdateHttp(bool $reloadOk = true): void
{
    Http::fake([
        '127.0.0.1:*' => $reloadOk
            ? Http::response(['apps' => ['frankenphp' => ['x' => true]]], 200)
            : Http::response('', 500),
        '*' => Http::response([], 200),
    ]);
}

test('deployment page lists Distribution Bundles with status for admins', function (): void {
    $user = createAdminUser();
    fakeDeploymentUpdateProcesses();
    Http::fake();

    $this->actingAs($user)
        ->get(route('admin.system.update.deployment.index'))
        ->assertOk()
        ->assertSee('Deployment')
        ->assertSee('Distribution Bundles')
        ->assertSee('Distribution Bundle')
        ->assertSee('A Distribution Bundle is BLB&#039;s installable, versioned code bundle.', false)
        ->assertSee('FrankenPHP workers')
        ->assertSee('No reload has been recorded yet.')
        ->assertSee('Belimbing (platform)')
        ->assertSee('BelimbingApp/belimbing') // discovered platform bundle's Git repository
        ->assertSee('Reload FrankenPHP')
        ->assertSee('does not pull code, install dependencies, build assets, or run migrations')
        ->assertDontSee('Code repositories');

    Http::assertSentCount(0);
});

test('failed remote checks name the repos instead of assuming they are private', function (): void {
    $user = createAdminUser();
    fakeDeploymentUpdateProcesses(remoteError: 'fatal: unable to access repository');
    Http::fake();

    $this->actingAs($user)
        ->get(route('admin.system.update.deployment.index'))
        ->assertOk()
        ->assertSee('Could not check latest commits for these Distribution Bundles: BelimbingApp/belimbing')
        ->assertSee('Public repositories do not need a token')
        ->assertSee('Could not read latest commit for BelimbingApp/belimbing@main via git ls-remote (fatal: unable to access repository)')
        ->assertDontSee('A private repository could not be checked');

    Http::assertSentCount(0);
});

test('reload only triggers a graceful worker reload and records a log', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);
    fakeDeploymentUpdateProcesses();
    Http::fake([
        '127.0.0.1:*' => Http::response(['apps' => ['frankenphp' => ['x' => true]]], 200),
        '*' => Http::response([], 200),
    ]);

    $component = Livewire::test(Index::class)
        ->call('reloadOnly')
        ->assertHasNoErrors();

    expect($component->get('log'))->not->toBeEmpty();

    $stored = app(SettingsService::class)->get('system.update.frankenphp.last_reload');

    expect(DB::table('base_settings')->where('key', 'system.update.frankenphp.last_reload')->exists())->toBeTrue()
        ->and($stored)->toBeArray()
        ->and($stored['ok'])->toBeTrue()
        ->and($stored['message'])->toBe('Web workers reloaded.');
});

test('deployment page shows the last frankenphp reload', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);
    fakeDeploymentUpdateProcesses();
    fakeDeploymentUpdateHttp();

    app(DeploymentService::class)->reload();

    Livewire::test(Index::class)
        ->assertSee('FrankenPHP workers')
        ->assertSee('Last run')
        ->assertSee('Workers reloaded')
        ->assertSee('Web workers reloaded.');
});

test('the previous run log persists at its rest location across page visits', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);
    fakeDeploymentUpdateProcesses();
    Http::fake([
        '127.0.0.1:*' => Http::response(['apps' => ['frankenphp' => ['x' => true]]], 200),
        '*' => Http::response([], 200),
    ]);

    $log = Livewire::test(Index::class)
        ->call('reloadOnly')
        ->get('log');

    expect($log)->not->toBeEmpty();

    // A fresh visit still shows the last run at rest (it is session-persisted); the
    // floating panel is purely client-side and never appears on a plain page load.
    Livewire::test(Index::class)->assertSet('log', $log);
});

test('manual frontend rebuild installs with the lockfile package manager and builds assets', function (): void {
    Process::fake();

    $log = app(DeploymentService::class)->rebuildAssets();

    expect($log)->toContain('Frontend dependencies installed.')
        ->and($log)->toContain('Frontend assets built.');

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
    $composerRun = app(DeploymentService::class)->lastComposerRun();
    $frontendRun = app(DeploymentService::class)->lastFrontendRun();

    expect($composerRun)->toBeArray()
        ->and($composerRun['ok'])->toBeTrue()
        ->and($composerRun['message'])->toBe('PHP dependencies installed.')
        ->and($frontendRun)->toBeArray()
        ->and($frontendRun['ok'])->toBeTrue()
        ->and($frontendRun['pm'])->toBe('bun')
        ->and($frontendRun['message'])->toBe('Frontend assets built.');
});

test('a failed frontend build records a needs-attention last run', function (): void {
    Process::fake(fn ($process) => $process->command === ['bun', 'run', 'build']
        ? Process::result(errorOutput: 'bun: command not found', exitCode: 127)
        : Process::result());

    app(DeploymentService::class)->rebuildAssets();

    $frontendRun = app(DeploymentService::class)->lastFrontendRun();

    expect($frontendRun)->toBeArray()
        ->and($frontendRun['ok'])->toBeFalse()
        ->and($frontendRun['message'])->toContain('Frontend asset build failed');
});

test('updating the platform pulls, refreshes runtime artifacts, migrates, and reloads', function (): void {
    fakeDeploymentUpdateProcesses();
    fakeDeploymentUpdateHttp();

    $log = app(DeploymentService::class)->update(['platform']);

    expect($log)->toContain('Building frontend assets…')
        ->and($log)->toContain('Frontend assets built.')
        ->and($log)->toContain('Verified: selected Distribution Bundles are up to date.')
        ->and($log)->toContain('Update complete. Selected Distribution Bundles are up to date and workers were reloaded.');

    Process::assertRan(fn ($process): bool => $process->command === ['git', 'pull', '--ff-only']);
    Process::assertRan(fn ($process): bool => in_array('dump-autoload', $process->command, true));
    Process::assertRan(fn ($process): bool => $process->command === ['bun', 'run', 'build']);
});

test('a run records a durable deployment last-run with its time and outcome', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);
    fakeDeploymentUpdateProcesses();
    fakeDeploymentUpdateHttp();

    Livewire::test(Index::class)
        ->call('reloadOnly')
        ->assertHasNoErrors();

    $run = app(DeploymentService::class)->lastDeploymentRun();

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
    app(DeploymentService::class)->rememberDeploymentRun(
        ['Pulling Belimbing (platform)…', 'Update complete. Selected Distribution Bundles are up to date and workers were reloaded.'],
        'success',
    );

    Livewire::test(Index::class)
        ->assertSee('Last run')
        ->assertSee('Update complete. Selected Distribution Bundles are up to date and workers were reloaded.');
});

test('the run card shows an empty state before any run has happened', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);
    fakeDeploymentUpdateProcesses();
    Http::fake();

    Livewire::test(Index::class)
        ->assertSee('Last run')
        ->assertSee('No update has run yet.');
});

test('the update console stays reachable during maintenance and can bring the site back online', function (): void {
    $user = createAdminUser();
    fakeDeploymentUpdateProcesses();
    Http::fake();

    Artisan::call('down');

    try {
        $this->actingAs($user)
            ->get(route('admin.system.update.deployment.index'))
            ->assertOk()
            ->assertSee('The site is in maintenance mode.');

        $this->actingAs($user)
            ->post(route('admin.system.update.online'))
            ->assertRedirect(route('admin.system.update.deployment.index'))
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

        expect($log)->toContain('Warning: web workers were not reloaded because the FrankenPHP admin API at http://127.0.0.1:2019/config/apps/frankenphp did not respond with config. Check CADDY_SERVER_ADMIN_HOST and CADDY_SERVER_ADMIN_PORT.')
            ->and($log)->toContain('Verified: selected Distribution Bundles are up to date.')
            ->and($log)->toContain('Update finished with warnings. Code may be updated, but one or more follow-up checks need attention.')
            ->and($log)->not->toContain('Update complete. Selected Distribution Bundles are up to date and workers were reloaded.');
    } finally {
        putenv('CADDY_SERVER_ADMIN_HOST');
        putenv('CADDY_SERVER_ADMIN_PORT');
    }
});

test('the worker reload reads its admin port from the octane server-state file', function (): void {
    // No env override → the port must come from octane's recorded state, not the
    // stock Caddy default of 2019 (which is the wrong port for our deployments).
    putenv('CADDY_SERVER_ADMIN_HOST');
    putenv('CADDY_SERVER_ADMIN_PORT');

    $statePath = storage_path('logs/octane-server-state.json');
    $backup = is_file($statePath) ? file_get_contents($statePath) : null;
    file_put_contents($statePath, json_encode(['state' => ['adminHost' => '127.0.0.1', 'adminPort' => 2643]]));

    try {
        fakeDeploymentUpdateProcesses();
        Http::fake(['*' => Http::response('', 500)]);

        $log = app(DeploymentService::class)->reload();

        expect(collect($log)->contains(fn (string $line): bool => str_contains($line, 'http://127.0.0.1:2643/config/apps/frankenphp')))->toBeTrue()
            ->and(collect($log)->contains(fn (string $line): bool => str_contains($line, ':2019/')))->toBeFalse();
    } finally {
        $backup === null ? @unlink($statePath) : file_put_contents($statePath, $backup);
    }
});
