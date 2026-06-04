<?php

use App\Modules\Core\AI\Enums\AiRunStatus;
use App\Modules\Core\AI\Enums\RunEventType;
use App\Modules\Core\AI\Models\AiRun;
use App\Modules\Core\AI\Models\AiRunEvent;
use App\Modules\Core\AI\Services\ControlPlane\WireLog\MetaMilestoneAnnotator;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

const MMA_EMPLOYEE_ID = 1;
const MMA_RUN_ID = 'run_mma_001';

function mmaMakeRun(string $runId = MMA_RUN_ID, array $overrides = []): AiRun
{
    return AiRun::unguarded(fn () => AiRun::query()->create(array_merge([
        'id' => $runId,
        'employee_id' => MMA_EMPLOYEE_ID,
        'session_id' => 'sess_mma',
        'source' => 'chat',
        'execution_mode' => 'streaming',
        'status' => AiRunStatus::Succeeded,
        'provider_name' => 'anthropic',
        'model' => 'claude-sonnet-4-6',
        'started_at' => Carbon::parse('2026-05-09T10:00:00+00:00'),
        'finished_at' => Carbon::parse('2026-05-09T10:01:00+00:00'),
    ], $overrides)));
}

function mmaAddEvent(AiRun $run, RunEventType $type, int $seq, string $isoCreatedAt, array $payload = []): AiRunEvent
{
    return AiRunEvent::unguarded(fn () => AiRunEvent::query()->create([
        'run_id' => $run->id,
        'seq' => $seq,
        'event_type' => $type,
        'payload' => $payload ?: null,
        'created_at' => Carbon::parse($isoCreatedAt),
    ]));
}

it('selects only structural milestone types and skips delta events', function (): void {
    $run = mmaMakeRun();
    mmaAddEvent($run, RunEventType::RunStarted, 1, '2026-05-09T10:00:00+00:00', ['provider' => 'anthropic', 'model' => 'claude']);
    mmaAddEvent($run, RunEventType::RunPhaseChanged, 2, '2026-05-09T10:00:01+00:00', ['phase' => 'booting', 'label' => 'Booting']);
    mmaAddEvent($run, RunEventType::AssistantThinkingStarted, 3, '2026-05-09T10:00:02+00:00');
    mmaAddEvent($run, RunEventType::AssistantThinkingDelta, 4, '2026-05-09T10:00:03+00:00', ['delta' => 'noise']);
    mmaAddEvent($run, RunEventType::AssistantOutputDelta, 5, '2026-05-09T10:00:04+00:00', ['delta' => 'noise']);
    mmaAddEvent($run, RunEventType::ToolStarted, 6, '2026-05-09T10:00:05+00:00');
    mmaAddEvent($run, RunEventType::ToolDenied, 7, '2026-05-09T10:00:06+00:00', ['tool' => 'http', 'reason' => 'policy denied']);
    mmaAddEvent($run, RunEventType::ToolFinished, 8, '2026-05-09T10:00:07+00:00');
    mmaAddEvent($run, RunEventType::UsageUpdated, 9, '2026-05-09T10:00:08+00:00');
    mmaAddEvent($run, RunEventType::RunCompleted, 10, '2026-05-09T10:00:09+00:00');
    mmaAddEvent($run, RunEventType::RunReadyForInput, 11, '2026-05-09T10:00:10+00:00');

    $milestones = app(MetaMilestoneAnnotator::class)->annotate($run);
    $types = array_column($milestones, 'type');

    expect($types)->toBe([
        'run.started',
        'run.phase_changed',
        'tool.denied',
        'run.completed',
        'run.ready_for_input',
    ]);
});

it('includes only heartbeats whose gap exceeds the 30s threshold', function (): void {
    $run = mmaMakeRun('run_mma_hb');
    mmaAddEvent($run, RunEventType::RunStarted, 1, '2026-05-09T10:00:00+00:00');
    // 10s gap — under threshold
    mmaAddEvent($run, RunEventType::Heartbeat, 2, '2026-05-09T10:00:10+00:00');
    // 45s gap from previous milestone (the heartbeat was skipped, so gap measures from start)
    // Actually gap is computed against previous *included* milestone.
    mmaAddEvent($run, RunEventType::Heartbeat, 3, '2026-05-09T10:00:55+00:00');
    mmaAddEvent($run, RunEventType::RunCompleted, 4, '2026-05-09T10:01:00+00:00');

    $milestones = app(MetaMilestoneAnnotator::class)->annotate($run);
    $types = array_column($milestones, 'type');

    expect($types)->toBe([
        'run.started',
        'heartbeat',
        'run.completed',
    ]);

    $heartbeat = $milestones[1];
    expect($heartbeat['has_gap_warning'])->toBeTrue()
        ->and($heartbeat['gap_ms'])->toBeGreaterThan(30_000);
});

it('computes gap_ms from the previous included milestone', function (): void {
    $run = mmaMakeRun('run_mma_gap');
    mmaAddEvent($run, RunEventType::RunStarted, 1, '2026-05-09T10:00:00+00:00');
    mmaAddEvent($run, RunEventType::AssistantOutputDelta, 2, '2026-05-09T10:00:30+00:00', ['delta' => 'hidden']);
    mmaAddEvent($run, RunEventType::RunCompleted, 3, '2026-05-09T10:01:00+00:00');

    $milestones = app(MetaMilestoneAnnotator::class)->annotate($run);

    expect($milestones)->toHaveCount(2)
        ->and($milestones[1]['type'])->toBe('run.completed')
        ->and($milestones[1]['gap_ms'])->toBe(60_000.0);
});

it('builds a meta-rail summary with counts by type, status, and phase progression', function (): void {
    $run = mmaMakeRun('run_mma_rail', ['status' => AiRunStatus::Succeeded]);
    mmaAddEvent($run, RunEventType::RunStarted, 1, '2026-05-09T10:00:00+00:00');
    mmaAddEvent($run, RunEventType::RunPhaseChanged, 2, '2026-05-09T10:00:01+00:00', ['phase' => 'booting']);
    mmaAddEvent($run, RunEventType::RunPhaseChanged, 3, '2026-05-09T10:00:02+00:00', ['phase' => 'running']);
    mmaAddEvent($run, RunEventType::RunCompleted, 4, '2026-05-09T10:00:03+00:00');

    $annotator = app(MetaMilestoneAnnotator::class);
    $milestones = $annotator->annotate($run);
    $rail = $annotator->buildRail($run, $milestones);

    expect($rail['total'])->toBe(4)
        ->and($rail['counts_by_type'])->toBe([
            'run.started' => 1,
            'run.phase_changed' => 2,
            'run.completed' => 1,
        ])
        ->and($rail['phase_progression'])->toBe(['booting', 'running'])
        ->and($rail['current_status'])->toBe('succeeded');
});

it('marks wire entries with meta events that fall in the timestamp slice they own (entry.at .. next_entry.at)', function (): void {
    $milestones = [
        ['type' => 'run.phase_changed', 'label' => 'Phase Changed', 'severity' => 'info', 'at' => '2026-05-09T10:00:05+00:00'],
        ['type' => 'tool.denied', 'label' => 'Tool Denied', 'severity' => 'warning', 'at' => '2026-05-09T10:00:15+00:00'],
    ];

    $entries = [
        ['entry_number' => 1, 'type' => 'llm.request', 'at' => '2026-05-09T10:00:00+00:00'],
        ['entry_number' => 2, 'type' => 'llm.stream_line', 'at' => '2026-05-09T10:00:10+00:00'],
        ['entry_number' => 3, 'type' => 'llm.stream_line', 'at' => '2026-05-09T10:00:20+00:00'],
    ];

    $annotated = app(MetaMilestoneAnnotator::class)->markEntriesWithMilestones($entries, $milestones);

    // Entry 1 [10:00:00, 10:00:10) → contains the 10:00:05 phase_changed milestone.
    expect($annotated[0]['meta_milestones'])->toHaveCount(1)
        ->and($annotated[0]['meta_milestones'][0]['type'])->toBe('run.phase_changed')
        // Entry 2 [10:00:10, 10:00:20) → contains the 10:00:15 tool_denied milestone.
        ->and($annotated[1]['meta_milestones'])->toHaveCount(1)
        ->and($annotated[1]['meta_milestones'][0]['type'])->toBe('tool.denied')
        // Entry 3 has no following entry and no milestone past 10:00:20 → unmarked.
        ->and($annotated[2])->not->toHaveKey('meta_milestones');
});
