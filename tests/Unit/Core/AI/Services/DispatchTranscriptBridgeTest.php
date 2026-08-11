<?php

use App\Core\AI\Enums\OperationStatus;
use App\Core\AI\Enums\OperationType;
use App\Core\AI\Models\AiRun;
use App\Core\AI\Models\OperationDispatch;
use App\Core\AI\Services\DispatchTranscriptBridge;
use App\Core\AI\Services\MessageManager;
use App\Core\AI\Services\SessionManager;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function (): void {
    config()->set('ai.workspace_path', storage_path('framework/testing/dispatch-transcript-'.Str::random(16)));
});

afterEach(function (): void {
    $workspacePath = config('ai.workspace_path');

    if (is_string($workspacePath)) {
        File::deleteDirectory($workspacePath);
    }
});

function createDispatchTranscriptFixture(): array
{
    provisionPlatformOperatorCompany('Test Company');
    Employee::provisionLara();

    $company = platformOperatorCompany();

    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'status' => 'active',
    ]);

    $user = User::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
    ]);

    test()->actingAs($user);

    $session = app(SessionManager::class)->create(Employee::LARA_ID);

    return [
        'user' => $user,
        'session_id' => $session->id,
    ];
}

it('appends a completion follow-up to the Lara session transcript', function (): void {
    $fixture = createDispatchTranscriptFixture();

    AiRun::unguarded(fn () => AiRun::query()->create([
        'id' => 'run_dispatch_success',
        'employee_id' => Employee::LARA_ID,
    ]));

    $dispatch = OperationDispatch::unguarded(fn () => OperationDispatch::query()->create([
        'id' => 'op_transcript_success',
        'operation_type' => OperationType::AgentTask,
        'employee_id' => Employee::LARA_ID,
        'acting_for_user_id' => $fixture['user']->id,
        'task' => 'Create a dashboard page',
        'status' => OperationStatus::Succeeded,
        'run_id' => 'run_dispatch_success',
        'result_summary' => 'Implemented the dashboard page.',
        'meta' => [
            'session_id' => $fixture['session_id'],
            'task_profile_label' => 'Coding',
        ],
    ]));

    app(DispatchTranscriptBridge::class)->appendSucceeded($dispatch);

    $messages = app(MessageManager::class)->read(Employee::LARA_ID, $fixture['session_id']);
    $lastMessage = $messages[count($messages) - 1] ?? null;

    expect($lastMessage)->not->toBeNull();
    expect($lastMessage?->role)->toBe('assistant');
    expect($lastMessage?->content)->toContain('Lara Coding completed the delegated task.');
    expect($lastMessage?->content)->toContain('Implemented the dashboard page.');
    expect($lastMessage?->runId)->toBe('run_dispatch_success');
});

it('appends a failure follow-up to the Lara session transcript', function (): void {
    $fixture = createDispatchTranscriptFixture();

    $dispatch = OperationDispatch::unguarded(fn () => OperationDispatch::query()->create([
        'id' => 'op_transcript_failed',
        'operation_type' => OperationType::AgentTask,
        'employee_id' => null,
        'acting_for_user_id' => $fixture['user']->id,
        'task' => 'Review the migration plan',
        'status' => OperationStatus::Failed,
        'error_message' => 'The delegated agent timed out.',
        'meta' => [
            'session_id' => $fixture['session_id'],
            'employee_name' => 'Code Worker',
        ],
    ]));

    app(DispatchTranscriptBridge::class)->appendFailed($dispatch, $dispatch->error_message ?? '');

    $messages = app(MessageManager::class)->read(Employee::LARA_ID, $fixture['session_id']);
    $lastMessage = $messages[count($messages) - 1] ?? null;

    expect($lastMessage)->not->toBeNull();
    expect($lastMessage?->role)->toBe('assistant');
    expect($lastMessage?->content)->toContain('Code Worker could not complete the delegated task.');
    expect($lastMessage?->content)->toContain('The delegated agent timed out.');
});
