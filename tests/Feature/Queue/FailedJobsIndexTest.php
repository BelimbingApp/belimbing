<?php

use App\Base\Queue\Livewire\FailedJobs\Index as FailedJobsIndex;
use App\Modules\Core\AI\Enums\AiRunStatus;
use App\Modules\Core\AI\Enums\RunPhase;
use App\Modules\Core\AI\Jobs\RunChatTurnJob;
use App\Modules\Core\AI\Models\AiRun;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

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

    failedJobsIndexInsertChatTurnFailure($terminalTurn->id, 'Terminal chat turn failure');
    failedJobsIndexInsertChatTurnFailure($queuedTurn->id, 'Queued chat turn failure');

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
        ->assertDontSee('Terminal chat turn failure')
        ->assertSee('Queued chat turn failure')
        ->assertSee('Ordinary queue failure');
});

it('does not retry terminal AI chat turn failures', function (): void {
    $user = createAdminUser();
    Employee::provisionLara();

    $terminalTurn = failedJobsIndexCreateChatTurn($user, AiRunStatus::Failed, RunPhase::Finalizing);
    $uuid = failedJobsIndexInsertChatTurnFailure($terminalTurn->id, 'Terminal chat turn failure');

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

    failedJobsIndexInsertChatTurnFailure($terminalTurn->id, 'Terminal chat turn failure');
    $queuedUuid = failedJobsIndexInsertChatTurnFailure($queuedTurn->id, 'Queued chat turn failure');

    Artisan::shouldReceive('call')
        ->once()
        ->with('queue:retry', ['id' => [$queuedUuid]])
        ->andReturn(0);

    Livewire::actingAs($user)
        ->test(FailedJobsIndex::class)
        ->call('retryAll');
});
