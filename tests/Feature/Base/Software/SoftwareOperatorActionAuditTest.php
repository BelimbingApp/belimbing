<?php

use App\Base\Audit\Services\AuditBuffer;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Software\Livewire\Deployment\Index as DeploymentIndex;
use App\Base\Software\Livewire\GitHubAccess\Index as GitHubAccessIndex;
use App\Base\Software\Services\DeploymentAdminEndpointResolver;
use App\Base\Software\Services\DeploymentBuildRunner;
use App\Base\Software\Services\DeploymentRunHistory;
use App\Base\Software\Services\DeploymentService;
use App\Base\Software\Services\SoftwareSourceRepository;
use App\Base\Software\Services\SoftwareUpdateLauncher;
use App\Base\Support\DetachedProcessLauncher;
use App\Core\User\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

const SOFTWARE_AUDIT_TOKEN = 'github_pat_software_audit_token_abcdefghijklmnopqrstuvwxyz';
const SOFTWARE_AUDIT_SHA = 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeef';
const SOFTWARE_AUDIT_REMOTE = 'https://github.com/BelimbingApp/belimbing.git';

function softwareAuditFlush(): void
{
    $buffer = app(AuditBuffer::class);
    (new ReflectionClass($buffer))->getMethod('flush')->invoke($buffer);
}

/** @return list<object> */
function softwareAuditEvents(string $event): array
{
    softwareAuditFlush();

    return DB::table('base_audit_actions')->where('event', $event)->orderBy('id')->get()->all();
}

function softwareAuditPayload(object $row): array
{
    $payload = json_decode((string) $row->payload, true);

    return is_array($payload) ? $payload : [];
}

function softwareAuditFakeGit(): void
{
    Process::fake(function ($process) {
        $command = $process->command;
        $withoutConfig = [];
        for ($i = 0; $i < count($command); $i++) {
            if ($command[$i] === '-c' && isset($command[$i + 1])) {
                $i++;

                continue;
            }
            $withoutConfig[] = $command[$i];
        }

        return match ($withoutConfig) {
            ['git', 'remote', 'get-url', 'origin'] => Process::result(SOFTWARE_AUDIT_REMOTE),
            ['git', 'status', '--porcelain=v1', '--branch'] => Process::result('## main...origin/main'),
            ['git', 'rev-parse', '--abbrev-ref', 'HEAD'] => Process::result('main'),
            ['git', 'log', '-1', '--format=%H%x1f%cI%x1f%an%x1f%s'] => Process::result(SOFTWARE_AUDIT_SHA."\x1f".now()->toIso8601String()."\x1fCI\x1fCurrent"),
            ['git', 'ls-remote', '--exit-code', 'origin', 'refs/heads/main'] => Process::result(SOFTWARE_AUDIT_SHA."\trefs/heads/main"),
            ['git', 'show', '-s', '--format=%H%x1f%cI%x1f%an%x1f%s', SOFTWARE_AUDIT_SHA] => Process::result(SOFTWARE_AUDIT_SHA."\x1f".now()->toIso8601String()."\x1fCI\x1fCurrent"),
            ['git', 'rev-list', '--left-right', '--count', SOFTWARE_AUDIT_SHA.'...HEAD'] => Process::result(errorOutput: 'fatal: bad revision', exitCode: 128),
            ['git', 'pull', '--ff-only'] => Process::result('Already up to date.'),
            default => Process::result(),
        };
    });
}

beforeEach(function (): void {
    Cache::flush();
});

it('records software.update.launched with the source key when updateRepo runs', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);
    softwareAuditFakeGit();
    Http::fake();

    $launcher = Mockery::mock(DetachedProcessLauncher::class);
    $launcher->shouldReceive('launch')->once()->andReturnTrue();
    app()->instance(DetachedProcessLauncher::class, $launcher);

    try {
        Livewire::test(DeploymentIndex::class)
            ->call('updateRepo', 'platform')
            ->assertDispatched('run-finished', status: 'pending', refresh: false)
            ->assertHasNoErrors();

        $rows = softwareAuditEvents('software.update.launched');
        expect($rows)->toHaveCount(1);

        $payload = softwareAuditPayload($rows[0]);
        $runId = app(DeploymentRunHistory::class)->lastDeploymentRun()['run_id'] ?? null;

        expect($payload['subject']['identifier'] ?? null)->toBe('platform')
            ->and($payload['surface'] ?? null)->toBe('admin.system.software.updates')
            ->and($payload['context']['run_id'] ?? null)->toBe($runId)
            ->and($payload['context']['source_keys'] ?? null)->toBe(['platform']);
    } finally {
        Cache::lock(SoftwareUpdateLauncher::LOCK_KEY)->forceRelease();
    }
});

it('records nothing when updateRepo is refused for lack of capability', function (): void {
    setupAuthzRoles();
    $user = User::factory()->create();
    $this->actingAs($user);
    softwareAuditFakeGit();
    Http::fake();

    $launcher = Mockery::mock(DetachedProcessLauncher::class);
    $launcher->shouldNotReceive('launch');
    app()->instance(DetachedProcessLauncher::class, $launcher);

    $component = Livewire::test(DeploymentIndex::class);

    // Deployment authorizeManage throws AuthorizationDeniedException (not abort(403)).
    expect(fn () => $component->call('updateRepo', 'platform'))
        ->toThrow(AuthorizationDeniedException::class);

    expect(softwareAuditEvents('software.update.launched'))->toBe([]);
});

it('records software.github_token.cleared with the owner and no token material', function (): void {
    app()->instance(DeploymentService::class, new class(app(SoftwareSourceRepository::class), app(DeploymentBuildRunner::class), app(DeploymentAdminEndpointResolver::class), app(DeploymentRunHistory::class)) extends DeploymentService
    {
        public function owners(): array
        {
            return [
                ['owner' => 'exampleowner', 'repos' => [['repo' => 'exampleowner/blb-ham', 'visibility' => 'private']], 'has_token' => true, 'all_public' => false],
            ];
        }
    });

    $user = createAdminUser();
    $this->actingAs($user);

    app(SettingsService::class)->set('integrations.github.token.exampleowner', SOFTWARE_AUDIT_TOKEN);

    Livewire::test(GitHubAccessIndex::class)
        ->call('clearToken', 'exampleowner')
        ->assertHasNoErrors();

    expect(app(SettingsService::class)->get('integrations.github.token.exampleowner'))->toBe('');

    $rows = softwareAuditEvents('software.github_token.cleared');
    expect($rows)->toHaveCount(1);

    $payload = softwareAuditPayload($rows[0]);
    $encoded = json_encode($payload);

    // One needle per negated assertion: multi-needle not->toContain only fails when all are present.
    expect($payload['subject']['identifier'] ?? null)->toBe('exampleowner')
        ->and($payload['surface'] ?? null)->toBe('admin.system.software.github-access')
        ->and($payload['context']['owner'] ?? null)->toBe('exampleowner')
        ->and($encoded)->not->toContain(SOFTWARE_AUDIT_TOKEN)
        ->and((string) ($payload['summary'] ?? ''))->not->toContain(SOFTWARE_AUDIT_TOKEN)
        ->and((string) $rows[0]->payload)->not->toContain(SOFTWARE_AUDIT_TOKEN);
});

it('records software.update.launched for all sources when updateAll runs with empty keys', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);
    softwareAuditFakeGit();
    Http::fake();

    $launcher = Mockery::mock(DetachedProcessLauncher::class);
    $launcher->shouldReceive('launch')->once()->andReturnTrue();
    app()->instance(DetachedProcessLauncher::class, $launcher);

    try {
        Livewire::test(DeploymentIndex::class)
            ->call('updateAll')
            ->assertDispatched('run-finished', status: 'pending', refresh: false)
            ->assertHasNoErrors();

        $rows = softwareAuditEvents('software.update.launched');
        expect($rows)->toHaveCount(1);

        $payload = softwareAuditPayload($rows[0]);
        $runId = app(DeploymentRunHistory::class)->lastDeploymentRun()['run_id'] ?? null;

        expect($payload['subject']['identifier'] ?? null)->toBe('all')
            ->and($payload['subject']['id'] ?? null)->toBe('all')
            ->and($payload['ui_element'] ?? null)->toBe('Update all')
            ->and($payload['surface'] ?? null)->toBe('admin.system.software.updates')
            ->and($payload['context']['run_id'] ?? null)->toBe($runId)
            ->and($payload['context']['source_keys'] ?? null)->toBe([]);
    } finally {
        Cache::lock(SoftwareUpdateLauncher::LOCK_KEY)->forceRelease();
    }
});

it('records software.rebuild.php when rebuildPhp runs', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);
    softwareAuditFakeGit();
    Http::fake();
    Process::fake();

    $deployment = Mockery::mock(DeploymentService::class)->makePartial();
    $deployment->shouldReceive('rebuildPhp')->once()->andReturn(['Installing PHP dependencies…', 'PHP dependencies installed.']);
    $deployment->shouldReceive('localStatus')->andReturn([]);
    $deployment->shouldReceive('status')->andReturn([]);
    $deployment->shouldReceive('frontendPackageManager')->andReturn('bun');
    app()->instance(DeploymentService::class, $deployment);

    try {
        Livewire::test(DeploymentIndex::class)
            ->call('rebuildPhp')
            ->assertHasNoErrors();

        $rows = softwareAuditEvents('software.rebuild.php');
        expect($rows)->toHaveCount(1);

        $payload = softwareAuditPayload($rows[0]);
        expect($payload['subject']['identifier'] ?? null)->toBe('php')
            ->and($payload['surface'] ?? null)->toBe('admin.system.software.updates')
            ->and($payload['ui_element'] ?? null)->toBe('Rebuild PHP');
    } finally {
        Cache::lock(SoftwareUpdateLauncher::LOCK_KEY)->forceRelease();
    }
});

it('records software.rebuild.assets when rebuildAssets runs', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);
    softwareAuditFakeGit();
    Http::fake();

    $deployment = Mockery::mock(DeploymentService::class)->makePartial();
    $deployment->shouldReceive('rebuildAssets')->once()->andReturn(['Building frontend assets…', 'Done.']);
    $deployment->shouldReceive('localStatus')->andReturn([]);
    $deployment->shouldReceive('status')->andReturn([]);
    $deployment->shouldReceive('frontendPackageManager')->andReturn('bun');
    app()->instance(DeploymentService::class, $deployment);

    try {
        Livewire::test(DeploymentIndex::class)
            ->call('rebuildAssets')
            ->assertHasNoErrors();

        $rows = softwareAuditEvents('software.rebuild.assets');
        expect($rows)->toHaveCount(1);

        $payload = softwareAuditPayload($rows[0]);
        expect($payload['subject']['identifier'] ?? null)->toBe('assets')
            ->and($payload['surface'] ?? null)->toBe('admin.system.software.updates')
            ->and($payload['ui_element'] ?? null)->toBe('Rebuild assets');
    } finally {
        Cache::lock(SoftwareUpdateLauncher::LOCK_KEY)->forceRelease();
    }
});

it('records software.github_token.stored with the owner and no token material', function (): void {
    app()->instance(DeploymentService::class, new class(app(SoftwareSourceRepository::class), app(DeploymentBuildRunner::class), app(DeploymentAdminEndpointResolver::class), app(DeploymentRunHistory::class)) extends DeploymentService
    {
        public function owners(): array
        {
            return [
                ['owner' => 'exampleowner', 'repos' => [['repo' => 'exampleowner/blb-ham', 'visibility' => 'private']], 'has_token' => false, 'all_public' => false],
            ];
        }

        public function saveToken(string $owner, string $token): void
        {
            app(SettingsService::class)->set('integrations.github.token.'.$owner, $token);
        }

        public function tokenFor(string $owner): ?string
        {
            $value = app(SettingsService::class)->get('integrations.github.token.'.$owner);

            return is_string($value) && $value !== '' ? $value : null;
        }
    });

    $user = createAdminUser();
    $this->actingAs($user);

    Livewire::test(GitHubAccessIndex::class)
        ->set('tokens.exampleowner', SOFTWARE_AUDIT_TOKEN)
        ->call('save', 'exampleowner')
        ->assertHasNoErrors();

    expect(app(SettingsService::class)->get('integrations.github.token.exampleowner'))->toBe(SOFTWARE_AUDIT_TOKEN);

    $rows = softwareAuditEvents('software.github_token.stored');
    expect($rows)->toHaveCount(1);

    $payload = softwareAuditPayload($rows[0]);
    $encoded = json_encode($payload);

    expect($payload['subject']['identifier'] ?? null)->toBe('exampleowner')
        ->and($payload['surface'] ?? null)->toBe('admin.system.software.github-access')
        ->and($payload['ui_element'] ?? null)->toBe('Save token')
        ->and($payload['context']['owner'] ?? null)->toBe('exampleowner')
        ->and($encoded)->not->toContain(SOFTWARE_AUDIT_TOKEN)
        ->and((string) ($payload['summary'] ?? ''))->not->toContain(SOFTWARE_AUDIT_TOKEN)
        ->and((string) $rows[0]->payload)->not->toContain(SOFTWARE_AUDIT_TOKEN);
});
