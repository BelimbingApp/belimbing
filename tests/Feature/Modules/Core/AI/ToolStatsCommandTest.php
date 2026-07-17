<?php

use App\Modules\Core\AI\Enums\AiRunStatus;
use App\Modules\Core\AI\Enums\RunPhase;
use App\Modules\Core\AI\Models\AiRun;
use App\Modules\Core\AI\Models\AiRunEvent;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;

function toolStatsSeedRun(): AiRun
{
    Company::provisionLicensee('Test Company');
    Employee::provisionLara();

    return AiRun::query()->create([
        'employee_id' => Employee::LARA_ID,
        'session_id' => 'tool-stats-test',
        'source' => 'chat',
        'execution_mode' => 'interactive',
        'status' => AiRunStatus::Succeeded,
        'current_phase' => RunPhase::Finalizing,
    ]);
}

function toolStatsEvent(AiRun $run, int $seq, string $type, array $payload): void
{
    AiRunEvent::query()->create([
        'run_id' => $run->id,
        'seq' => $seq,
        'event_type' => $type,
        'payload' => $payload,
    ]);
}

test('tool stats aggregates calls, errors, denials, and gap signals from run events', function (): void {
    $run = toolStatsSeedRun();

    toolStatsEvent($run, 1, 'tool.finished', ['tool' => 'read', 'status' => 'success', 'duration_ms' => 120]);
    toolStatsEvent($run, 2, 'tool.finished', ['tool' => 'read', 'status' => 'success', 'duration_ms' => 80]);
    toolStatsEvent($run, 3, 'tool.finished', [
        'tool' => 'bash',
        'status' => 'error',
        'duration_ms' => 30,
        'error_payload' => ['code' => 'execution_failed', 'message' => 'boom'],
    ]);
    toolStatsEvent($run, 4, 'tool.finished', [
        'tool' => 'approve_leave',
        'status' => 'error',
        'error_payload' => ['code' => 'unknown_tool', 'message' => 'Unknown tool "approve_leave".'],
    ]);
    toolStatsEvent($run, 5, 'tool.denied', ['tool' => 'edit', 'reason' => 'policy', 'source' => 'hook']);

    $this->artisan('blb:ai:tools:stats', ['--days' => 7])
        ->expectsOutputToContain('read')
        ->expectsOutputToContain('approve_leave')
        ->expectsOutputToContain('candidate gaps')
        ->assertExitCode(0);
});

test('tool stats reports an empty window cleanly', function (): void {
    toolStatsSeedRun();

    $this->artisan('blb:ai:tools:stats', ['--days' => 7])
        ->expectsOutputToContain('No tool activity')
        ->assertExitCode(0);
});
