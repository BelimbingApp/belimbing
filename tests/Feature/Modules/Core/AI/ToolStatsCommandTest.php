<?php

use App\Modules\Core\AI\Enums\AiRunStatus;
use App\Modules\Core\AI\Enums\RunPhase;
use App\Modules\Core\AI\Models\AiRun;
use App\Modules\Core\AI\Models\AiRunEvent;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Support\Facades\Artisan;

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
    toolStatsEvent($run, 6, 'tool.finished', [
        'tool' => 'bash',
        'status' => 'error',
        'error_payload' => ['code' => 'permission_denied', 'message' => 'Not allowed'],
    ]);
    toolStatsEvent($run, 7, 'tool.denied', ['tool' => 'bash', 'reason' => 'Not allowed', 'source' => 'authorization']);

    expect(Artisan::call('blb:ai:tools:stats', ['--days' => 7]))->toBe(0);

    $output = Artisan::output();

    expect($output)
        ->toContain('| read          | 2     | 0      | 0      | 0.0     | 100')
        ->toContain('| bash          | 2     | 2      | 1      | 100.0   | 30')
        ->toContain('| approve_leave | 1     | 1      | 0      | 100.0   | -')
        ->toContain('| edit          | 1     | 0      | 1      | 0.0     | -')
        ->toContain('candidate gaps');
});

test('tool stats reports an empty window cleanly', function (): void {
    toolStatsSeedRun();

    $this->artisan('blb:ai:tools:stats', ['--days' => 7])
        ->expectsOutputToContain('No tool activity')
        ->assertExitCode(0);
});
