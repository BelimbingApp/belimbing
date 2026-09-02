<?php

use App\Core\AI\Enums\AiRunStatus;
use App\Core\AI\Enums\RunEventType;
use App\Core\AI\Models\AiRun;
use App\Core\AI\Models\AiRunEvent;
use App\Core\AI\Services\ControlPlane\RunDiagnosticService;
use App\Core\AI\Services\ControlPlane\WireLogger;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

const RDLC_EMPLOYEE_ID = 1;
// ai_runs.id is a ULID char(26): PostgreSQL pads shorter ids on read.
const RDLC_RUN_ID = '01jtstrdc00000000000000001';

beforeEach(function (): void {
    config()->set('ai.workspace_path', storage_path('framework/testing/rdlc-'.Str::random(16)));
    config()->set('ai.wire_logging.enabled', true);

    $this->wireLogRoot = storage_path('framework/testing/rdlc-wire-'.Str::random(16));

    app()->instance(WireLogger::class, new class($this->wireLogRoot) extends WireLogger
    {
        public function __construct(private readonly string $root) {}

        public function path(string $runId): string
        {
            return $this->root.'/'.$runId.'.jsonl';
        }
    });
});

afterEach(function (): void {
    if (isset($this->wireLogRoot) && is_string($this->wireLogRoot)) {
        File::deleteDirectory($this->wireLogRoot);
    }
});

function rdlcMakeRun(string $runId = RDLC_RUN_ID): AiRun
{
    return AiRun::unguarded(fn () => AiRun::query()->create([
        'id' => $runId,
        'employee_id' => RDLC_EMPLOYEE_ID,
        'session_id' => 'sess_rdlc',
        'source' => 'chat',
        'execution_mode' => 'streaming',
        'status' => AiRunStatus::Succeeded,
        'provider_name' => 'anthropic',
        'model' => 'claude-sonnet-4-6',
        'started_at' => Carbon::parse('2026-05-09T10:00:00+00:00'),
        'finished_at' => Carbon::parse('2026-05-09T10:01:00+00:00'),
    ]));
}

function rdlcAddEvent(AiRun $run, RunEventType $type, int $seq, string $isoCreatedAt, array $payload = []): void
{
    AiRunEvent::unguarded(fn () => AiRunEvent::query()->create([
        'run_id' => $run->id,
        'seq' => $seq,
        'event_type' => $type,
        'payload' => $payload ?: null,
        'created_at' => Carbon::parse($isoCreatedAt),
    ]));
}

it('exposes lifecycle milestones and a meta rail in the Run Inspector view-model alongside StreamAssembler output', function (): void {
    $run = rdlcMakeRun();

    rdlcAddEvent($run, RunEventType::RunStarted, 1, '2026-05-09T10:00:00+00:00', ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6']);
    rdlcAddEvent($run, RunEventType::RunPhaseChanged, 2, '2026-05-09T10:00:01+00:00', ['phase' => 'booting']);
    rdlcAddEvent($run, RunEventType::AssistantOutputDelta, 3, '2026-05-09T10:00:02+00:00', ['delta' => 'noise']);
    rdlcAddEvent($run, RunEventType::RunCompleted, 4, '2026-05-09T10:00:03+00:00');

    $wireLogger = app(WireLogger::class);
    $wireLogger->append($run->id, [
        'type' => 'llm.request',
        'request' => ['model' => 'claude-sonnet-4-6', 'messages' => [['role' => 'user', 'content' => 'hi']]],
    ]);
    $wireLogger->append($run->id, [
        'type' => 'llm.complete',
        'context' => [],
    ]);

    $view = app(RunDiagnosticService::class)->buildRunView($run->id);

    expect($view)->not->toBeNull()
        ->and($view['lifecycle_milestones'])->toHaveCount(3) // delta excluded
        ->and(array_column($view['lifecycle_milestones'], 'type'))->toBe([
            'run.started',
            'run.phase_changed',
            'run.completed',
        ])
        ->and($view['lifecycle_rail']['total'])->toBe(3)
        ->and($view['lifecycle_rail']['current_status'])->toBe('succeeded')
        ->and($view['lifecycle_rail']['phase_progression'])->toBe(['booting'])
        ->and($view['wire_log_readable']['has_entries'])->toBeTrue()
        ->and($view['wire_log_readable']['attempts'])->not->toBeEmpty();
});

it('returns an empty milestone list and zero rail total for a run with only delta events', function (): void {
    $run = rdlcMakeRun('01jtstrdcempty000000000001');
    rdlcAddEvent($run, RunEventType::AssistantOutputDelta, 1, '2026-05-09T10:00:00+00:00', ['delta' => 'a']);
    rdlcAddEvent($run, RunEventType::AssistantOutputDelta, 2, '2026-05-09T10:00:01+00:00', ['delta' => 'b']);

    $view = app(RunDiagnosticService::class)->buildRunView($run->id);

    expect($view['lifecycle_milestones'])->toBe([])
        ->and($view['lifecycle_rail']['total'])->toBe(0);
});

it('rebuilds diagnostics after scoped instances are flushed', function (): void {
    $first = app(RunDiagnosticService::class);

    app()->forgetScopedInstances();

    expect(app(RunDiagnosticService::class))->not->toBe($first);
});

it('uses real ULID-shaped fixture ids for ai_runs.id', function (): void {
    // char(26) pads shorter ids on PostgreSQL (#507); lowercase Crockford,
    // excluding i, l, o and u, is what DataShareTransferOfferBundle::isUlid() accepts.
    foreach ([RDLC_RUN_ID, '01jtstrdcempty000000000001'] as $fixtureId) {
        expect($fixtureId)->toMatch('/^[0-9a-hjkmnp-tv-z]{26}$/');
    }
});
