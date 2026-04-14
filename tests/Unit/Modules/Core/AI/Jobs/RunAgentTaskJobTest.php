<?php

use App\Modules\Core\AI\Enums\OperationStatus;
use App\Modules\Core\AI\Enums\OperationType;
use App\Modules\Core\AI\Jobs\RunAgentTaskJob;
use App\Modules\Core\AI\Models\OperationDispatch;
use App\Modules\Core\AI\Services\AgentTaskPromptFactory;
use App\Modules\Core\AI\Services\AgentExecutionContext;
use App\Modules\Core\AI\Services\AgenticRuntime;
use App\Modules\Core\AI\Services\DispatchTranscriptBridge;
use App\Modules\Core\AI\Services\Workspace\PromptRenderer;
use App\Modules\Core\User\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

it('clears auth and execution context when returning early for a terminal dispatch', function () {
    $user = User::factory()->create();
    $dispatch = OperationDispatch::unguarded(fn () => OperationDispatch::query()->create([
        'id' => 'op_terminal_cleanup',
        'operation_type' => OperationType::AgentTask,
        'employee_id' => 1,
        'acting_for_user_id' => $user->id,
        'task' => 'Already done',
        'status' => OperationStatus::Succeeded,
        'meta' => null,
    ]));

    Auth::login($user);

    $context = app(AgentExecutionContext::class);
    $context->set(
        employeeId: $dispatch->employee_id,
        actingForUserId: $dispatch->acting_for_user_id,
        entityType: null,
        entityId: null,
        dispatchId: $dispatch->id,
    );

    $job = new RunAgentTaskJob($dispatch->id);
    $runtime = Mockery::mock(AgenticRuntime::class);
    $promptFactory = Mockery::mock(AgentTaskPromptFactory::class);
    $renderer = new PromptRenderer;

    $job->handle($runtime, $promptFactory, app(DispatchTranscriptBridge::class), $renderer, $context);

    expect(Auth::check())->toBeFalse()
        ->and($context->active())->toBeFalse();
});
