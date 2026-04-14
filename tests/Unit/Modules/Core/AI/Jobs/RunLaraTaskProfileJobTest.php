<?php

use App\Modules\Core\AI\DTO\LaraTaskExecutionProfile;
use App\Modules\Core\AI\Enums\ExecutionMode;
use App\Modules\Core\AI\Enums\OperationStatus;
use App\Modules\Core\AI\Enums\OperationType;
use App\Modules\Core\AI\Jobs\RunLaraTaskProfileJob;
use App\Modules\Core\AI\Models\OperationDispatch;
use App\Modules\Core\AI\Services\AgentExecutionContext;
use App\Modules\Core\AI\Services\AgenticRuntime;
use App\Modules\Core\AI\Services\ConfigResolver;
use App\Modules\Core\AI\Services\DispatchTranscriptBridge;
use App\Modules\Core\AI\Services\LaraPromptFactory;
use App\Modules\Core\AI\Services\LaraTaskExecutionProfileRegistry;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

it('runs the Lara coding task profile and clears auth and execution context', function (): void {
    $user = User::factory()->create();

    $dispatch = OperationDispatch::unguarded(fn () => OperationDispatch::query()->create([
        'id' => 'op_lara_task_profile',
        'operation_type' => OperationType::AgentTask,
        'employee_id' => Employee::LARA_ID,
        'acting_for_user_id' => $user->id,
        'task' => 'Build a dashboard page',
        'status' => OperationStatus::Queued,
        'meta' => [
            'task_profile' => 'coding',
        ],
    ]));

    Auth::login($user);

    $context = app(AgentExecutionContext::class);
    $context->set(
        employeeId: Employee::LARA_ID,
        actingForUserId: $user->id,
        entityType: null,
        entityId: null,
        dispatchId: $dispatch->id,
    );

    $profile = new LaraTaskExecutionProfile(
        taskKey: 'coding',
        label: 'Coding',
        systemPromptPath: app_path('Modules/Core/AI/Resources/tasks/coding/system_prompt.md'),
        allowedToolNames: ['bash', 'edit_file'],
        executionMode: ExecutionMode::Background,
    );

    $runtime = Mockery::mock(AgenticRuntime::class);
    $runtime->shouldReceive('run')
        ->once()
        ->withArgs(function (
            array $messages,
            int $employeeId,
            string $systemPrompt,
            ?string $modelOverride,
            $policy,
            ?string $sessionId,
            array $configOverride,
            array $allowedToolNames,
        ): bool {
            return $employeeId === Employee::LARA_ID
                && $messages[0]->content === 'Build a dashboard page'
                && str_contains($systemPrompt, 'Base Lara prompt')
                && str_contains($systemPrompt, 'coding task profile')
                && $modelOverride === null
                && $sessionId === null
                && $configOverride['model'] === 'gpt-coder'
                && $allowedToolNames === ['bash', 'edit_file'];
        })
        ->andReturn([
            'content' => 'Implemented the dashboard page.',
            'run_id' => 'run_profile_001',
            'meta' => ['model' => 'gpt-coder'],
        ]);

    $promptFactory = Mockery::mock(LaraPromptFactory::class);
    $promptFactory->shouldReceive('buildForCurrentUser')
        ->once()
        ->with('Build a dashboard page')
        ->andReturn('Base Lara prompt');

    $profileRegistry = Mockery::mock(LaraTaskExecutionProfileRegistry::class);
    $profileRegistry->shouldReceive('find')
        ->once()
        ->with('coding')
        ->andReturn($profile);
    $profileRegistry->shouldReceive('composeSystemPrompt')
        ->once()
        ->with($profile, 'Base Lara prompt')
        ->andReturn("Base Lara prompt\n\nTask profile instructions:\nYou are running Lara's coding task profile.");

    $configResolver = Mockery::mock(ConfigResolver::class);
    $configResolver->shouldReceive('resolveTaskWithPrimaryFallback')
        ->once()
        ->with(Employee::LARA_ID, 'coding')
        ->andReturn([
            'api_key' => 'key',
            'base_url' => 'https://api.example.test/v1',
            'model' => 'gpt-coder',
            'max_tokens' => 2048,
            'temperature' => 0.7,
            'timeout' => 60,
            'provider_name' => 'openai',
        ]);

    $job = new RunLaraTaskProfileJob($dispatch->id);
    $job->handle(
        $runtime,
        $context,
        $configResolver,
        app(DispatchTranscriptBridge::class),
        $promptFactory,
        $profileRegistry,
    );

    $dispatch->refresh();

    expect($dispatch->status)->toBe(OperationStatus::Succeeded)
        ->and($dispatch->run_id)->toBe('run_profile_001')
        ->and($dispatch->result_summary)->toBe('Implemented the dashboard page.')
        ->and(data_get($dispatch->meta, 'task_profile'))->toBe('coding')
        ->and(Auth::check())->toBeFalse()
        ->and($context->active())->toBeFalse();
});

it('runs the Lara research task profile with the resolved research model', function (): void {
    $user = User::factory()->create();

    $dispatch = OperationDispatch::unguarded(fn () => OperationDispatch::query()->create([
        'id' => 'op_lara_research_profile',
        'operation_type' => OperationType::AgentTask,
        'employee_id' => Employee::LARA_ID,
        'acting_for_user_id' => $user->id,
        'task' => 'Investigate the latest AI provider docs changes',
        'status' => OperationStatus::Queued,
        'meta' => [
            'task_profile' => 'research',
        ],
    ]));

    $profile = new LaraTaskExecutionProfile(
        taskKey: 'research',
        label: 'Research',
        systemPromptPath: app_path('Modules/Core/AI/Resources/tasks/research/system_prompt.md'),
        allowedToolNames: ['guide', 'web_search', 'web_fetch'],
        executionMode: ExecutionMode::Background,
    );

    $runtime = Mockery::mock(AgenticRuntime::class);
    $runtime->shouldReceive('run')
        ->once()
        ->withArgs(function (
            array $messages,
            int $employeeId,
            string $systemPrompt,
            ?string $modelOverride,
            $policy,
            ?string $sessionId,
            array $configOverride,
            array $allowedToolNames,
        ): bool {
            return $employeeId === Employee::LARA_ID
                && $messages[0]->content === 'Investigate the latest AI provider docs changes'
                && str_contains($systemPrompt, 'Base Lara prompt')
                && str_contains($systemPrompt, 'research task profile')
                && $modelOverride === null
                && $sessionId === null
                && $configOverride['model'] === 'gpt-research'
                && $allowedToolNames === ['guide', 'web_search', 'web_fetch'];
        })
        ->andReturn([
            'content' => 'Collected the latest provider docs changes.',
            'run_id' => 'run_profile_002',
            'meta' => ['model' => 'gpt-research'],
        ]);

    $promptFactory = Mockery::mock(LaraPromptFactory::class);
    $promptFactory->shouldReceive('buildForCurrentUser')
        ->once()
        ->with('Investigate the latest AI provider docs changes')
        ->andReturn('Base Lara prompt');

    $profileRegistry = Mockery::mock(LaraTaskExecutionProfileRegistry::class);
    $profileRegistry->shouldReceive('find')
        ->once()
        ->with('research')
        ->andReturn($profile);
    $profileRegistry->shouldReceive('composeSystemPrompt')
        ->once()
        ->with($profile, 'Base Lara prompt')
        ->andReturn("Base Lara prompt\n\nTask profile instructions:\nYou are running Lara's research task profile.");

    $configResolver = Mockery::mock(ConfigResolver::class);
    $configResolver->shouldReceive('resolveTaskWithPrimaryFallback')
        ->once()
        ->with(Employee::LARA_ID, 'research')
        ->andReturn([
            'api_key' => 'key',
            'base_url' => 'https://api.example.test/v1',
            'model' => 'gpt-research',
            'max_tokens' => 2048,
            'temperature' => 0.7,
            'timeout' => 60,
            'provider_name' => 'openai',
        ]);

    $job = new RunLaraTaskProfileJob($dispatch->id);
    $job->handle(
        $runtime,
        app(AgentExecutionContext::class),
        $configResolver,
        app(DispatchTranscriptBridge::class),
        $promptFactory,
        $profileRegistry,
    );

    $dispatch->refresh();

    expect($dispatch->status)->toBe(OperationStatus::Succeeded)
        ->and($dispatch->run_id)->toBe('run_profile_002')
        ->and($dispatch->result_summary)->toBe('Collected the latest provider docs changes.')
        ->and(data_get($dispatch->meta, 'task_profile'))->toBe('research');
});
