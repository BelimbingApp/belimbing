<?php

use App\Base\Settings\Contracts\SettingsService;
use App\Base\Update\Livewire\Belimbing\Index;
use App\Base\Update\Services\DeploymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

const BELIMBING_UPDATE_SHA = 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeef';

function fakeBelimbingUpdateProcesses(string $sha = BELIMBING_UPDATE_SHA, ?string $remoteError = null): void
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

function fakeBelimbingUpdateHttp(bool $reloadOk = true): void
{
    Http::fake([
        '127.0.0.1:*' => $reloadOk
            ? Http::response(['apps' => ['frankenphp' => ['x' => true]]], 200)
            : Http::response('', 500),
        '*' => Http::response([], 200),
    ]);
}

test('belimbing update page lists Distribution Bundles with status for admins', function (): void {
    $user = createAdminUser();
    fakeBelimbingUpdateProcesses();
    Http::fake();

    $this->actingAs($user)
        ->get(route('admin.system.update.belimbing.index'))
        ->assertOk()
        ->assertSee('Distribution Bundles')
        ->assertSee('Distribution Bundle')
        ->assertSee('A Distribution Bundle is BLB&#039;s installable, versioned code bundle.', false)
        ->assertSee('FrankenPHP reload')
        ->assertSee('No FrankenPHP reload has been recorded yet.')
        ->assertSee('Belimbing (platform)')
        ->assertSee('BelimbingApp/belimbing') // discovered platform bundle's Git repository
        ->assertSee('Reload FrankenPHP')
        ->assertSee('does not pull code, install dependencies, build assets, or run migrations')
        ->assertDontSee('Code repositories');

    Http::assertSentCount(0);
});

test('failed remote checks name the repos instead of assuming they are private', function (): void {
    $user = createAdminUser();
    fakeBelimbingUpdateProcesses(remoteError: 'fatal: unable to access repository');
    Http::fake();

    $this->actingAs($user)
        ->get(route('admin.system.update.belimbing.index'))
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
    fakeBelimbingUpdateProcesses();
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

test('belimbing update page shows the last frankenphp reload', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);
    fakeBelimbingUpdateProcesses();
    fakeBelimbingUpdateHttp();

    app(DeploymentService::class)->reload();

    Livewire::test(Index::class)
        ->assertSee('FrankenPHP reload')
        ->assertSee('Last attempted')
        ->assertSee('Workers reloaded')
        ->assertSee('Web workers reloaded.');
});

test('run log can be closed and reopened with the previous run', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);
    fakeBelimbingUpdateProcesses();
    Http::fake([
        '127.0.0.1:*' => Http::response(['apps' => ['frankenphp' => ['x' => true]]], 200),
        '*' => Http::response([], 200),
    ]);

    $component = Livewire::test(Index::class)
        ->call('reloadOnly')
        ->assertSet('logPanelOpen', true)
        ->call('closeRunLog')
        ->assertSet('logPanelOpen', false);

    $log = $component->get('log');

    expect($log)->not->toBeEmpty();

    Livewire::test(Index::class)
        ->assertSet('log', $log)
        ->assertSet('logPanelOpen', false)
        ->call('showLastRun')
        ->assertSet('logPanelOpen', true)
        ->assertSet('log', $log);
});

test('manual frontend rebuild installs with the lockfile package manager and builds assets', function (): void {
    Process::fake();

    $log = app(DeploymentService::class)->rebuildAssets();

    expect($log)->toContain('Frontend dependencies installed.')
        ->and($log)->toContain('Frontend assets built.');

    Process::assertRan(fn ($process): bool => array_slice($process->command, 0, 3) === ['bun', 'install', '--frozen-lockfile']);
    Process::assertRan(fn ($process): bool => $process->command === ['bun', 'run', 'build']);
});

test('updating the platform pulls, refreshes runtime artifacts, migrates, and reloads', function (): void {
    fakeBelimbingUpdateProcesses();
    fakeBelimbingUpdateHttp();

    $log = app(DeploymentService::class)->update(['platform']);

    expect($log)->toContain('Building frontend assets…')
        ->and($log)->toContain('Frontend assets built.')
        ->and($log)->toContain('Verified: selected Distribution Bundles are up to date.')
        ->and($log)->toContain('Update complete. Selected Distribution Bundles are up to date and workers were reloaded.');

    Process::assertRan(fn ($process): bool => $process->command === ['git', 'pull', '--ff-only']);
    Process::assertRan(fn ($process): bool => in_array('dump-autoload', $process->command, true));
    Process::assertRan(fn ($process): bool => $process->command === ['bun', 'run', 'build']);
});

test('update reports reload problems as warnings instead of clean completion', function (): void {
    fakeBelimbingUpdateProcesses();
    fakeBelimbingUpdateHttp(reloadOk: false);

    $log = app(DeploymentService::class)->update(['platform']);

    expect($log)->toContain('Warning: web workers were not reloaded because the FrankenPHP admin API at http://127.0.0.1:2019/config/apps/frankenphp did not respond with config. Check CADDY_SERVER_ADMIN_HOST and CADDY_SERVER_ADMIN_PORT.')
        ->and($log)->toContain('Verified: selected Distribution Bundles are up to date.')
        ->and($log)->toContain('Update finished with warnings. Code may be updated, but one or more follow-up checks need attention.')
        ->and($log)->not->toContain('Update complete. Selected Distribution Bundles are up to date and workers were reloaded.');
});
