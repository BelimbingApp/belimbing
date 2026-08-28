<?php

use App\Base\Foundation\Enums\StatusVariant;
use App\Base\Schedule\Contracts\ScheduleHealthContributor;
use App\Base\Schedule\DTO\UnhealthyScheduleTask;
use App\Base\Schedule\Models\ScheduleRun;
use App\Base\Schedule\Models\ScheduleSuppression;
use App\Base\Schedule\Services\ScheduleHealthService;
use App\Base\Schedule\Services\ScheduleRunRecorder;
use App\Base\Schedule\Services\ScheduleStatusBarDiagnosticProvider;
use App\Base\System\Contracts\StatusBarDiagnosticProvider;
use App\Base\System\DTO\StatusBarDiagnostic;
use App\Core\AI\Enums\OperationStatus;
use App\Core\AI\Enums\OperationType;
use App\Core\AI\Models\OperationDispatch;
use App\Core\AI\Models\ScheduleDefinition;
use App\Core\Company\Models\Company;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

const SINGLE_SCHEDULE_FAILURE_SUMMARY = '1 scheduled task failing';

beforeEach(function (): void {
    Process::fake();
    ScheduleHealthService::invalidate();
});

function scheduleHealthTestKey(string $command = 'inspire'): string
{
    $event = app(Schedule::class)->command($command)->description($command);

    return app(ScheduleRunRecorder::class)->key($event);
}

it('links the inactive Lara status-bar action to AI Providers with setup guidance', function (): void {
    $this->actingAs(createAdminUser());

    $response = $this->get(route('admin.system.info.index'));

    $response->assertOk()
        ->assertSee('href="'.route('admin.ai.providers').'"', false)
        ->assertSee('title="Activate Lara"', false)
        ->assertSee('Activate Lara')
        ->assertSee('laraActivated: false', false);
});

it('renders tagged diagnostics in the status bar detail surface', function (): void {
    $provider = new class implements StatusBarDiagnosticProvider
    {
        public function diagnosticsFor(Authenticatable $user): iterable
        {
            return [
                new StatusBarDiagnostic(
                    id: 'test.status-bar.warning',
                    severity: StatusVariant::Warning,
                    source: 'Menu',
                    summary: 'Synthetic warning',
                    detail: 'Diagnostic detail',
                    target: route('admin.system.menu-inspector.index'),
                ),
            ];
        }
    };

    $this->app->instance($provider::class, $provider);
    $this->app->tag([$provider::class], StatusBarDiagnosticProvider::CONTAINER_TAG);

    $this->actingAs(createAdminUser());

    $response = $this->get(route('admin.system.info.index'));

    $response->assertOk()
        ->assertSee('1 diagnostic')
        ->assertSee('Synthetic warning')
        ->assertSee('Diagnostic detail')
        ->assertSee('href="'.route('admin.system.menu-inspector.index').'"', false)
        ->assertSee('aria-label="Close diagnostics"', false)
        ->assertSee('Open related page')
        ->assertDontSee('aria-label="Open related diagnostics"', false);
});

it('reports only stale previously recorded schedule activity', function (): void {
    $run = ScheduleRun::query()->create([
        'source' => 'scheduler',
        'key' => 'maintenance:test',
        'name' => 'maintenance:test',
        'status' => 'succeeded',
        'started_at' => now()->subMinutes(20),
        'finished_at' => now()->subMinutes(19),
    ]);

    $this->actingAs(createAdminUser());

    $this->get(route('admin.system.info.index'))
        ->assertOk()
        ->assertSee('No recent scheduled activity was recorded');

    $run->forceFill([
        'started_at' => now(),
        'finished_at' => now(),
    ])->save();

    $this->get(route('admin.system.info.index'))
        ->assertOk()
        ->assertDontSee('No recent scheduled activity was recorded');
});

it('flags a scheduler task whose latest run failed', function (): void {
    $key = scheduleHealthTestKey();

    ScheduleRun::query()->create([
        'source' => 'scheduler',
        'key' => $key,
        'name' => 'inspire',
        'status' => 'failed',
        'started_at' => now()->subMinutes(5),
        'finished_at' => now()->subMinutes(4),
    ]);

    $this->actingAs(createAdminUser());

    $this->get(route('admin.system.info.index'))
        ->assertOk()
        ->assertSee(SINGLE_SCHEDULE_FAILURE_SUMMARY)
        ->assertSee('inspire');
});

it('aggregates multiple failing scheduler tasks into one diagnostic', function (): void {
    foreach ([scheduleHealthTestKey('inspire'), scheduleHealthTestKey('inspire:second')] as $key) {
        ScheduleRun::query()->create([
            'source' => 'scheduler',
            'key' => $key,
            'name' => $key,
            'status' => 'failed',
            'started_at' => now()->subMinutes(5),
            'finished_at' => now()->subMinutes(4),
        ]);
    }

    $this->actingAs(createAdminUser());

    $this->get(route('admin.system.info.index'))
        ->assertOk()
        ->assertSee('2 scheduled tasks failing');
});

it('escalates repeated consecutive failures to danger', function (): void {
    $key = scheduleHealthTestKey();

    foreach ([10, 5] as $minutes) {
        ScheduleRun::query()->create([
            'source' => 'scheduler',
            'key' => $key,
            'name' => 'inspire',
            'status' => 'failed',
            'started_at' => now()->subMinutes($minutes),
            'finished_at' => now()->subMinutes($minutes - 1),
        ]);
    }

    $user = createAdminUser();
    $this->actingAs($user);

    $diagnostic = collect(app(ScheduleStatusBarDiagnosticProvider::class)->diagnosticsFor($user))
        ->firstWhere('id', 'schedule.failing-tasks');

    expect($diagnostic)->not->toBeNull()
        ->and($diagnostic->severity)->toBe(StatusVariant::Error);
});

it('clears a failure after a later successful run', function (): void {
    $key = scheduleHealthTestKey();

    ScheduleRun::query()->create([
        'source' => 'scheduler',
        'key' => $key,
        'name' => 'inspire',
        'status' => 'failed',
        'started_at' => now()->subMinutes(10),
        'finished_at' => now()->subMinutes(9),
    ]);
    ScheduleRun::query()->create([
        'source' => 'scheduler',
        'key' => $key,
        'name' => 'inspire',
        'status' => 'succeeded',
        'started_at' => now()->subMinutes(5),
        'finished_at' => now()->subMinutes(4),
    ]);

    $user = createAdminUser();
    $this->actingAs($user);

    $diagnostic = collect(app(ScheduleStatusBarDiagnosticProvider::class)->diagnosticsFor($user))
        ->firstWhere('id', 'schedule.failing-tasks');

    expect($diagnostic)->toBeNull();
});

it('does not flag paused or removed scheduler tasks', function (): void {
    $pausedKey = scheduleHealthTestKey('inspire');
    ScheduleSuppression::query()->create([
        'source' => 'scheduler',
        'key' => $pausedKey,
        'name' => 'inspire',
    ]);

    foreach ([$pausedKey, 'removed-task'] as $key) {
        ScheduleRun::query()->create([
            'source' => 'scheduler',
            'key' => $key,
            'name' => $key,
            'status' => 'failed',
            'started_at' => now()->subMinutes(5),
            'finished_at' => now()->subMinutes(4),
        ]);
    }

    $user = createAdminUser();
    $this->actingAs($user);

    expect(collect(app(ScheduleStatusBarDiagnosticProvider::class)->diagnosticsFor($user))
        ->firstWhere('id', 'schedule.failing-tasks'))->toBeNull();
});

it('includes contributor failures without invoking the full board projection', function (): void {
    $contributor = new class implements ScheduleHealthContributor
    {
        public function unhealthyTasks(): array
        {
            return [new UnhealthyScheduleTask(
                source: 'test-contributor',
                key: 'test-contributor:failed',
                name: 'Contributor failure',
                lastAttemptAt: now()->subMinutes(3),
                consecutiveFailures: 1,
            )];
        }
    };
    app()->instance('schedule-test-health-contributor', $contributor);
    app()->tag(['schedule-test-health-contributor'], ScheduleHealthContributor::CONTAINER_TAG);

    $user = createAdminUser();
    $this->actingAs($user);

    $this->get(route('admin.system.info.index'))
        ->assertOk()
        ->assertSee(SINGLE_SCHEDULE_FAILURE_SUMMARY)
        ->assertSee('Contributor failure');
});

it('includes failures from the AI schedule contributor projection', function (): void {
    $definition = ScheduleDefinition::query()->create([
        'company_id' => Company::factory()->create()->id,
        'source' => 'core-ai',
        'source_key' => 'nightly-summary',
        'executor' => ScheduleDefinition::EXECUTOR_AGENTIC_RUNTIME,
        'description' => 'Nightly summary',
        'execution_payload' => 'Summarize the day',
        'cron_expression' => '0 2 * * *',
        'timezone' => 'UTC',
        'is_enabled' => true,
        'concurrency_policy' => 'skip',
    ]);

    OperationDispatch::query()->create([
        'id' => 'op_health_failed',
        'operation_type' => OperationType::ScheduledTask,
        'task' => 'Nightly summary',
        'status' => OperationStatus::Failed,
        'meta' => ['schedule_id' => $definition->id],
        'started_at' => now()->subMinutes(5),
        'finished_at' => now()->subMinutes(4),
    ]);

    $user = createAdminUser();
    $this->actingAs($user);

    $this->get(route('admin.system.info.index'))
        ->assertOk()
        ->assertSee('1 scheduled task failing')
        ->assertSee('nightly-summary');
});

it('keeps failure details safe and the warm snapshot query-free', function (): void {
    $key = scheduleHealthTestKey();
    ScheduleRun::query()->create([
        'source' => 'scheduler',
        'key' => $key,
        'name' => 'inspire',
        'status' => 'failed',
        'started_at' => now()->subMinutes(5),
        'finished_at' => now()->subMinutes(4),
        'output_excerpt' => 'secret-token-and-connection-string',
    ]);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $snapshot = app(ScheduleHealthService::class)->snapshot();
    $afterColdSnapshot = $queries;
    app(ScheduleHealthService::class)->snapshot();

    expect($snapshot->unhealthyTasks)->toHaveCount(1)
        ->and($queries)->toBe($afterColdSnapshot);

    $this->actingAs(createAdminUser());
    $this->get(route('admin.system.info.index'))
        ->assertOk()
        ->assertDontSee('secret-token-and-connection-string');
});
