<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\Enums\AiRunStatus;
use App\Modules\Core\AI\Enums\RunEventType;
use App\Modules\Core\AI\Models\AiRun;
use App\Modules\Core\AI\Models\AiRunEvent;
use App\Modules\Core\AI\Services\ControlPlane\RunDiagnosticService;
use App\Modules\Core\AI\Services\ControlPlane\WireLogger;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

const PTL_EMPLOYEE_ID = 1;

beforeEach(function (): void {
    config()->set('ai.workspace_path', storage_path('framework/testing/ptl-'.Str::random(16)));
    config()->set('ai.wire_logging.enabled', true);

    $this->wireLogRoot = storage_path('framework/testing/ptl-wire-logs-'.Str::random(16));

    app()->instance(WireLogger::class, new class($this->wireLogRoot) extends WireLogger
    {
        public function __construct(private readonly string $root) {}

        public function path(string $runId): string
        {
            return $this->root.'/'.$runId.'.jsonl';
        }

        public function totalBytes(): int
        {
            $total = 0;

            foreach (glob($this->root.'/*.jsonl') ?: [] as $path) {
                $size = @filesize($path);

                if ($size !== false) {
                    $total += $size;
                }
            }

            return $total;
        }
    });
});

afterEach(function (): void {
    if (isset($this->wireLogRoot) && is_string($this->wireLogRoot)) {
        File::deleteDirectory($this->wireLogRoot);
    }
});

function ptlMakeRun(string $runId, array $overrides = []): AiRun
{
    return AiRun::unguarded(fn () => AiRun::query()->create(array_merge([
        'id' => $runId,
        'employee_id' => PTL_EMPLOYEE_ID,
        'session_id' => 'sess_ptl_001',
        'source' => 'chat',
        'execution_mode' => 'streaming',
        'status' => AiRunStatus::Succeeded,
        'provider_name' => 'anthropic',
        'model' => 'claude-sonnet-4-6',
        'started_at' => now()->subSeconds(10),
        'finished_at' => now(),
    ], $overrides)));
}

function ptlAddEvent(AiRun $run, RunEventType $type, int $seq, array $payload = [], Carbon|string|null $createdAt = null): AiRunEvent
{
    return AiRunEvent::unguarded(fn () => AiRunEvent::query()->create([
        'run_id' => $run->id,
        'seq' => $seq,
        'event_type' => $type,
        'payload' => $payload ?: null,
        'created_at' => $createdAt instanceof Carbon ? $createdAt : ($createdAt !== null ? Carbon::parse($createdAt) : now()->addMilliseconds($seq * 100)),
    ]));
}

// ------------------------------------------------------------------
// returns null for unknown run
// ------------------------------------------------------------------

it('returns null when the run does not exist', function (): void {
    $service = app(RunDiagnosticService::class);

    expect($service->buildPromptTimelineView('nonexistent_run'))->toBeNull();
});

// ------------------------------------------------------------------
// empty run — no events, no wire log
// ------------------------------------------------------------------

it('returns an empty timeline for a run with no events and no wire log', function (): void {
    $run = ptlMakeRun('ptl_run_001');

    $service = app(RunDiagnosticService::class);
    $view = $service->buildPromptTimelineView($run->id);

    expect($view)->not->toBeNull()
        ->and($view['timeline'])->toBe([])
        ->and($view['meta_count'])->toBe(0)
        ->and($view['wire_count'])->toBe(0)
        ->and($view['has_wire_log'])->toBeFalse()
        ->and($view['delta_collapsed'])->toBeFalse();
});

// ------------------------------------------------------------------
// meta events are included and sorted
// ------------------------------------------------------------------

it('includes meta events in the timeline', function (): void {
    $run = ptlMakeRun('ptl_run_002');
    ptlAddEvent($run, RunEventType::RunStarted, 1);
    ptlAddEvent($run, RunEventType::RunCompleted, 2);

    $service = app(RunDiagnosticService::class);
    $view = $service->buildPromptTimelineView($run->id);

    expect($view)->not->toBeNull()
        ->and($view['meta_count'])->toBe(2)
        ->and($view['wire_count'])->toBe(0)
        ->and($view['timeline'])->toHaveCount(2)
        ->and($view['timeline'][0]['source'])->toBe('meta')
        ->and($view['timeline'][0]['type'])->toBe('run.started')
        ->and($view['timeline'][1]['type'])->toBe('run.completed');
});

// ------------------------------------------------------------------
// wire entries are merged from the wire log
// ------------------------------------------------------------------

it('merges wire log entries with meta events in chronological order', function (): void {
    $run = ptlMakeRun('ptl_run_003');
    ptlAddEvent($run, RunEventType::RunStarted, 1, []);

    $wireLogger = app(WireLogger::class);
    $wireLogger->append($run->id, [
        'type' => 'llm.request',
        'at' => now()->addSeconds(2)->toIso8601String(),
        'request' => ['model' => 'claude-sonnet-4-6', 'messages' => [['role' => 'user', 'content' => 'Hello']]],
    ]);
    $wireLogger->append($run->id, [
        'type' => 'llm.complete',
        'at' => now()->addSeconds(3)->toIso8601String(),
        'context' => [],
    ]);

    $service = app(RunDiagnosticService::class);
    $view = $service->buildPromptTimelineView($run->id);

    expect($view)->not->toBeNull()
        ->and($view['has_wire_log'])->toBeTrue()
        ->and($view['meta_count'])->toBe(1)
        ->and($view['wire_count'])->toBe(2)
        ->and($view['timeline'])->toHaveCount(3)
        ->and($view['timeline'][0]['source'])->toBe('meta')
        ->and($view['timeline'][1]['source'])->toBe('wire')
        ->and($view['timeline'][1]['type'])->toBe('llm.request')
        ->and($view['timeline'][1]['entry_number'])->toBe(1)
        ->and($view['timeline'][2]['type'])->toBe('llm.complete');
});

// ------------------------------------------------------------------
// wire summary for llm.request includes model and message count
// ------------------------------------------------------------------

it('builds a summary for llm.request wire entries', function (): void {
    $run = ptlMakeRun('ptl_run_004');

    $wireLogger = app(WireLogger::class);
    $wireLogger->append($run->id, [
        'type' => 'llm.request',
        'request' => [
            'model' => 'gpt-coder',
            'messages' => [
                ['role' => 'user', 'content' => 'Build a dashboard'],
                ['role' => 'assistant', 'content' => 'Sure'],
            ],
        ],
    ]);

    $service = app(RunDiagnosticService::class);
    $view = $service->buildPromptTimelineView($run->id);

    $wireEntry = $view['timeline'][0];

    expect($wireEntry['source'])->toBe('wire')
        ->and($wireEntry['summary'])->toContain('gpt-coder')
        ->and($wireEntry['summary'])->toContain('2');
});

// ------------------------------------------------------------------
// delta collapse — hides llm.stream_line and delta meta events
// ------------------------------------------------------------------

it('collapses delta entries when collapseDelta is true', function (): void {
    $run = ptlMakeRun('ptl_run_005');
    ptlAddEvent($run, RunEventType::RunStarted, 1);
    ptlAddEvent($run, RunEventType::AssistantOutputDelta, 2, ['delta' => 'Hello']);
    ptlAddEvent($run, RunEventType::AssistantOutputDelta, 3, ['delta' => ' world']);
    ptlAddEvent($run, RunEventType::RunCompleted, 4);

    $wireLogger = app(WireLogger::class);
    $wireLogger->append($run->id, ['type' => 'llm.stream_line', 'raw_line' => 'data: chunk1']);
    $wireLogger->append($run->id, ['type' => 'llm.stream_line', 'raw_line' => 'data: chunk2']);
    $wireLogger->append($run->id, ['type' => 'llm.complete', 'context' => []]);

    $service = app(RunDiagnosticService::class);

    $expanded = $service->buildPromptTimelineView($run->id, collapseDelta: false);
    $collapsed = $service->buildPromptTimelineView($run->id, collapseDelta: true);

    expect($expanded['meta_count'])->toBe(4)
        ->and($expanded['wire_count'])->toBe(3)
        ->and($collapsed['meta_count'])->toBe(2)
        ->and($collapsed['wire_count'])->toBe(1)
        ->and($collapsed['delta_collapsed'])->toBeTrue();
});

it('measures collapsed meta gaps from the last visible meta event', function (): void {
    $run = ptlMakeRun('ptl_run_008');
    ptlAddEvent($run, RunEventType::RunStarted, 1, [], '2026-05-08T10:00:00+00:00');
    ptlAddEvent($run, RunEventType::AssistantOutputDelta, 2, ['delta' => 'hidden'], '2026-05-08T10:01:00+00:00');
    ptlAddEvent($run, RunEventType::RunCompleted, 3, [], '2026-05-08T10:02:00+00:00');

    $service = app(RunDiagnosticService::class);
    $collapsed = $service->buildPromptTimelineView($run->id, collapseDelta: true);

    expect($collapsed['timeline'])->toHaveCount(2)
        ->and($collapsed['timeline'][1]['type'])->toBe('run.completed')
        ->and($collapsed['timeline'][1]['gap_ms'])->toBe(120000.0);
});

it('sorts mixed timestamp offsets by instant instead of lexical string order', function (): void {
    $run = ptlMakeRun('ptl_run_009');
    ptlAddEvent($run, RunEventType::RunStarted, 1, [], '2026-05-08T10:00:00+00:00');

    app(WireLogger::class)->append($run->id, [
        'type' => 'llm.request',
        'at' => '2026-05-08T10:30:00+01:00',
        'request' => ['model' => 'claude-sonnet-4-6', 'messages' => []],
    ]);

    $service = app(RunDiagnosticService::class);
    $view = $service->buildPromptTimelineView($run->id);

    expect($view['timeline'])->toHaveCount(2)
        ->and($view['timeline'][0]['source'])->toBe('wire')
        ->and($view['timeline'][1]['source'])->toBe('meta');
});

// ------------------------------------------------------------------
// llm.error wire entry gets error severity
// ------------------------------------------------------------------

it('marks llm.error wire entries with error severity', function (): void {
    $run = ptlMakeRun('ptl_run_006');

    $wireLogger = app(WireLogger::class);
    $wireLogger->append($run->id, [
        'type' => 'llm.error',
        'stage' => 'stream',
        'message' => 'Connection reset',
    ]);

    $service = app(RunDiagnosticService::class);
    $view = $service->buildPromptTimelineView($run->id);

    $entry = $view['timeline'][0];

    expect($entry['severity'])->toBe('error')
        ->and($entry['summary'])->toBe('Connection reset');
});

// ------------------------------------------------------------------
// entry_number is 1-based and wire entries have no seq
// ------------------------------------------------------------------

it('assigns 1-based entry_number to wire entries and null seq', function (): void {
    $run = ptlMakeRun('ptl_run_007');

    $wireLogger = app(WireLogger::class);
    $wireLogger->append($run->id, [
        'type' => 'llm.request',
        'at' => now()->subSeconds(5)->toIso8601String(),
        'request' => ['model' => 'm', 'messages' => []],
    ]);
    $wireLogger->append($run->id, [
        'type' => 'llm.complete',
        'at' => now()->subSeconds(1)->toIso8601String(),
        'context' => [],
    ]);

    $service = app(RunDiagnosticService::class);
    $view = $service->buildPromptTimelineView($run->id);

    expect($view['timeline'])->toHaveCount(2)
        ->and($view['timeline'][0]['entry_number'])->toBe(1)
        ->and($view['timeline'][0]['seq'])->toBeNull()
        ->and($view['timeline'][1]['entry_number'])->toBe(2);
});
