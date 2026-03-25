<?php

use App\Modules\Core\AI\Jobs\RunAgentTaskJob;
use App\Modules\Core\AI\Models\AgentTaskDispatch;
use App\Modules\Core\AI\Services\AgentExecutionContext;
use App\Modules\Core\AI\Services\AgenticRuntime;
use App\Modules\Core\AI\Services\KodiPromptFactory;
use App\Modules\Core\User\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

it('clears auth and execution context when returning early for a terminal dispatch', function () {
    $user = User::factory()->create();
    $dispatch = AgentTaskDispatch::unguarded(fn () => AgentTaskDispatch::query()->create([
        'id' => 'agent_dispatch_terminal_cleanup',
        'employee_id' => 1,
        'acting_for_user_id' => $user->id,
        'task' => 'Already done',
        'status' => 'succeeded',
        'meta' => null,
    ]));

    Auth::login($user);

    $context = app(AgentExecutionContext::class);
    $context->set(
        employeeId: $dispatch->employee_id,
        actingForUserId: $dispatch->acting_for_user_id,
        ticketId: null,
        dispatchId: $dispatch->id,
    );

    $job = new RunAgentTaskJob($dispatch->id);
    $runtime = Mockery::mock(AgenticRuntime::class);
    $promptFactory = Mockery::mock(KodiPromptFactory::class);

    $job->handle($runtime, $promptFactory, $context);

    expect(Auth::check())->toBeFalse()
        ->and($context->active())->toBeFalse();
});
