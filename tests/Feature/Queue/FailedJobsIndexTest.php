<?php

use App\Base\Audit\Services\AuditBuffer;
use App\Base\Queue\Livewire\FailedJobs\Index as FailedJobsIndex;
use App\Core\AI\Enums\AiRunStatus;
use App\Core\AI\Enums\RunPhase;
use App\Core\AI\Jobs\RunChatTurnJob;
use App\Core\AI\Models\AiRun;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

const FAILED_JOBS_TERMINAL_CHAT_TURN_FAILURE = 'Terminal chat turn failure';

const FAILED_JOBS_QUEUED_CHAT_TURN_FAILURE = 'Queued chat turn failure';

function failedJobsIndexCreateChatTurn(User $user, AiRunStatus $status, RunPhase $phase): AiRun
{
    return AiRun::query()->create([
        'employee_id' => Employee::LARA_ID,
        'session_id' => 'failed-jobs-index',
        'acting_for_user_id' => $user->id,
        'source' => 'chat',
        'execution_mode' => 'interactive',
        'status' => $status,
        'current_phase' => $phase,
    ]);
}

function failedJobsIndexInsertChatTurnFailure(string $runId, string $exception): string
{
    $uuid = (string) Str::uuid();
    $job = new RunChatTurnJob($runId);

    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => RunChatTurnJob::QUEUE,
        'payload' => json_encode([
            'uuid' => $uuid,
            'displayName' => $job->displayName(),
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => [
                'commandName' => RunChatTurnJob::class,
                'command' => serialize($job),
            ],
        ]),
        'exception' => $exception,
        'failed_at' => now(),
    ]);

    return $uuid;
}

it('hides terminal AI chat turn failures from failed jobs', function (): void {
    $user = createAdminUser();
    Employee::provisionLara();

    $terminalTurn = failedJobsIndexCreateChatTurn($user, AiRunStatus::Failed, RunPhase::Finalizing);
    $queuedTurn = failedJobsIndexCreateChatTurn($user, AiRunStatus::Queued, RunPhase::WaitingForWorker);

    failedJobsIndexInsertChatTurnFailure($terminalTurn->id, FAILED_JOBS_TERMINAL_CHAT_TURN_FAILURE);
    failedJobsIndexInsertChatTurnFailure($queuedTurn->id, FAILED_JOBS_QUEUED_CHAT_TURN_FAILURE);

    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'OrdinaryFailedJob']),
        'exception' => 'Ordinary queue failure',
        'failed_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(FailedJobsIndex::class)
        ->assertDontSee(FAILED_JOBS_TERMINAL_CHAT_TURN_FAILURE)
        ->assertSee(FAILED_JOBS_QUEUED_CHAT_TURN_FAILURE)
        ->assertSee('Ordinary queue failure');
});

it('does not retry terminal AI chat turn failures', function (): void {
    $user = createAdminUser();
    Employee::provisionLara();

    $terminalTurn = failedJobsIndexCreateChatTurn($user, AiRunStatus::Failed, RunPhase::Finalizing);
    $uuid = failedJobsIndexInsertChatTurnFailure($terminalTurn->id, FAILED_JOBS_TERMINAL_CHAT_TURN_FAILURE);

    Artisan::shouldReceive('call')->never();

    Livewire::actingAs($user)
        ->test(FailedJobsIndex::class)
        ->call('retryJob', $uuid);
});

it('retries only actionable failed jobs from retry all', function (): void {
    $user = createAdminUser();
    Employee::provisionLara();

    $terminalTurn = failedJobsIndexCreateChatTurn($user, AiRunStatus::Failed, RunPhase::Finalizing);
    $queuedTurn = failedJobsIndexCreateChatTurn($user, AiRunStatus::Queued, RunPhase::WaitingForWorker);

    failedJobsIndexInsertChatTurnFailure($terminalTurn->id, FAILED_JOBS_TERMINAL_CHAT_TURN_FAILURE);
    $queuedUuid = failedJobsIndexInsertChatTurnFailure($queuedTurn->id, FAILED_JOBS_QUEUED_CHAT_TURN_FAILURE);

    Artisan::shouldReceive('call')
        ->once()
        ->with('queue:retry', ['id' => [$queuedUuid]])
        ->andReturnUsing(function () use ($queuedUuid): int {
            DB::table('failed_jobs')->where('uuid', $queuedUuid)->delete();

            return 0;
        });

    Livewire::actingAs($user)
        ->test(FailedJobsIndex::class)
        ->call('retryAll');
});

it('records queue.failed_job.retried for each retried uuid from retryAll and none for terminal AI failures', function (): void {
    $user = createAdminUser();
    Employee::provisionLara();

    $terminalTurn = failedJobsIndexCreateChatTurn($user, AiRunStatus::Failed, RunPhase::Finalizing);
    $queuedTurn = failedJobsIndexCreateChatTurn($user, AiRunStatus::Queued, RunPhase::WaitingForWorker);

    failedJobsIndexInsertChatTurnFailure($terminalTurn->id, FAILED_JOBS_TERMINAL_CHAT_TURN_FAILURE);
    $queuedUuid = failedJobsIndexInsertChatTurnFailure($queuedTurn->id, FAILED_JOBS_QUEUED_CHAT_TURN_FAILURE);

    Artisan::shouldReceive('call')
        ->once()
        ->with('queue:retry', ['id' => [$queuedUuid]])
        ->andReturnUsing(function () use ($queuedUuid): int {
            DB::table('failed_jobs')->where('uuid', $queuedUuid)->delete();

            return 0;
        });

    Livewire::actingAs($user)
        ->test(FailedJobsIndex::class)
        ->call('retryAll');

    $buffer = app(AuditBuffer::class);
    (new ReflectionClass($buffer))->getMethod('flush')->invoke($buffer);

    $rows = DB::table('base_audit_actions')->where('event', 'queue.failed_job.retried')->orderBy('id')->get()->all();
    expect($rows)->toHaveCount(1);
    $payload = json_decode((string) $rows[0]->payload, true);
    expect($payload['subject']['identifier'] ?? null)->toBe($queuedUuid)
        ->and($payload['context']['uuid'] ?? null)->toBe($queuedUuid);
});

it('records no queue.failed_job.retried when queue:retry exits non-zero', function (): void {
    $user = createAdminUser();
    $uuid = (string) Str::uuid();
    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'RetryFailJob']),
        'exception' => 'retry will fail',
        'failed_at' => now(),
    ]);

    Artisan::shouldReceive('call')
        ->once()
        ->with('queue:retry', ['id' => [$uuid]])
        ->andReturn(1);

    Livewire::actingAs($user)
        ->test(FailedJobsIndex::class)
        ->call('retryJob', $uuid);

    $buffer = app(AuditBuffer::class);
    (new ReflectionClass($buffer))->getMethod('flush')->invoke($buffer);

    expect(DB::table('base_audit_actions')->where('event', 'queue.failed_job.retried')->count())->toBe(0);
    expect(DB::table('failed_jobs')->where('uuid', $uuid)->exists())->toBeTrue();
});
