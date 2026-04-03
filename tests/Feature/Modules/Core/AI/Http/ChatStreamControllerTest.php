<?php

use App\Modules\Core\AI\Enums\AiRunStatus;
use App\Modules\Core\AI\Models\AiRun;
use App\Modules\Core\AI\Services\AgenticRuntime;
use App\Modules\Core\AI\Services\MessageManager;
use App\Modules\Core\AI\Services\SessionManager;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config()->set('ai.workspace_path', storage_path('framework/testing/ai-stream-'.Str::random(16)));
});

afterEach(function (): void {
    $workspacePath = config('ai.workspace_path');

    if (is_string($workspacePath)) {
        File::deleteDirectory($workspacePath);
    }
});

/**
 * @return array{user: User}
 */
function createStreamingLaraFixture(): array
{
    Company::provisionLicensee('Test Company');
    Employee::provisionLara();

    $company = Company::query()->findOrFail(Company::LICENSEE_ID);
    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'status' => 'active',
    ]);

    return [
        'user' => User::factory()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
        ]),
    ];
}

it('persists provider and model metadata for streaming runtime errors', function (): void {
    $fixture = createStreamingLaraFixture();
    $this->actingAs($fixture['user']);

    $sessionManager = app(SessionManager::class);
    $messageManager = app(MessageManager::class);
    $session = $sessionManager->create(Employee::LARA_ID);

    $messageManager->appendUserMessage(Employee::LARA_ID, $session->id, 'Hello Lara');

    $runtime = Mockery::mock(AgenticRuntime::class);
    $runtime->shouldReceive('runStream')->once()->andReturn((function (): Generator {
        yield [
            'event' => 'error',
            'data' => [
                'message' => __('The AI provider did not respond in time. Please try again.'),
                'run_id' => 'run_stream_timeout',
                'meta' => [
                    'message_type' => 'error',
                    'error_type' => 'timeout',
                    'provider_name' => 'google',
                    'model' => 'gemma-3',
                    'llm' => [
                        'provider' => 'google',
                        'model' => 'gemma-3',
                    ],
                ],
            ],
        ];
    })());

    app()->instance(AgenticRuntime::class, $runtime);

    AiRun::query()->create([
        'id' => 'run_stream_timeout',
        'employee_id' => Employee::LARA_ID,
        'session_id' => $session->id,
        'source' => 'stream',
        'execution_mode' => 'interactive',
        'status' => AiRunStatus::Failed,
        'provider_name' => 'google',
        'model' => 'gemma-3',
        'error_type' => 'timeout',
        'error_message' => 'The AI provider did not respond in time. Please try again.',
        'started_at' => now(),
        'finished_at' => now(),
    ]);

    $response = $this->get(route('ai.chat.stream', [
        'employee_id' => Employee::LARA_ID,
        'session_id' => $session->id,
    ]));

    $response->assertOk();
    $response->streamedContent();

    $messages = $messageManager->read(Employee::LARA_ID, $session->id);

    expect($messages)->toHaveCount(2)
        ->and($messages[1]->role)->toBe('assistant')
        ->and($messages[1]->content)->toContain('⚠')
        ->and($messages[1]->runId)->toBe('run_stream_timeout')
        ->and($messages[1]->meta['message_type'])->toBe('error')
        ->and($messages[1]->meta['provider_name'])->toBe('google')
        ->and($messages[1]->meta['model'])->toBe('gemma-3')
        ->and($messages[1]->meta['llm']['provider'])->toBe('google')
        ->and($messages[1]->meta['llm']['model'])->toBe('gemma-3');
});
