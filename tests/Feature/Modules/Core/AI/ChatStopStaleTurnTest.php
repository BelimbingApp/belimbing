<?php

use App\Modules\Core\AI\Enums\AiRunStatus;
use App\Modules\Core\AI\Enums\TurnPhase;
use App\Modules\Core\AI\Enums\TurnStatus;
use App\Modules\Core\AI\Livewire\Chat;
use App\Modules\Core\AI\Models\AiRun;
use App\Modules\Core\AI\Models\ChatTurn;
use App\Modules\Core\AI\Services\MessageManager;
use App\Modules\Core\AI\Services\SessionManager;
use App\Modules\Core\AI\Services\TurnEventPublisher;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Livewire;

const STOP_STALE_TEST_RUN_ID = 'run_stop_stale_001';
const STOP_STALE_TEST_PROVIDER = 'openai';
const STOP_STALE_TEST_MODEL = 'gpt-5';
const STOP_STALE_TEST_OUTPUT = 'Hello world';

beforeEach(function (): void {
    config()->set('ai.workspace_path', storage_path('framework/testing/ai-stop-stale-'.Str::random(16)));
});

afterEach(function (): void {
    $workspacePath = config('ai.workspace_path');

    if (is_string($workspacePath)) {
        File::deleteDirectory($workspacePath);
    }
});

/**
 * @return array{user: User, lara: Employee}
 */
function createStopStaleFixture(): array
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
        'lara' => Employee::query()->findOrFail(Employee::LARA_ID),
    ];
}

it('stopping a stale turn materializes streamed assistant output with run metadata', function (): void {
    $fixture = createStopStaleFixture();
    $this->actingAs($fixture['user']);

    $session = app(SessionManager::class)->create(Employee::LARA_ID);

    $turn = ChatTurn::query()->create([
        'employee_id' => Employee::LARA_ID,
        'session_id' => $session->id,
        'acting_for_user_id' => $fixture['user']->id,
        'status' => TurnStatus::Queued,
        'current_phase' => TurnPhase::WaitingForWorker,
    ]);

    $publisher = app(TurnEventPublisher::class);
    $publisher->turnStarted($turn);
    $publisher->runStarted($turn, STOP_STALE_TEST_RUN_ID, STOP_STALE_TEST_PROVIDER, STOP_STALE_TEST_MODEL);
    $turn->transitionTo(TurnStatus::Running);
    $publisher->phaseChanged($turn, TurnPhase::StreamingAnswer, 'Responding…');
    $publisher->outputDelta($turn, 'Hello ');
    $publisher->outputDelta($turn, 'world');

    AiRun::query()->create([
        'id' => STOP_STALE_TEST_RUN_ID,
        'employee_id' => Employee::LARA_ID,
        'session_id' => $session->id,
        'acting_for_user_id' => $fixture['user']->id,
        'turn_id' => $turn->id,
        'source' => 'chat',
        'execution_mode' => 'interactive',
        'status' => AiRunStatus::Running,
        'provider_name' => STOP_STALE_TEST_PROVIDER,
        'model' => STOP_STALE_TEST_MODEL,
        'started_at' => now()->subMinutes(340),
    ]);

    $turn->forceFill([
        'created_at' => now()->subMinutes(340),
        'started_at' => now()->subMinutes(340),
    ])->save();

    Livewire::test(Chat::class)
        ->call('cancelActiveTurn', $turn->id);

    $turn->refresh();

    expect($turn->status)->toBe(TurnStatus::Cancelled);

    $messages = app(MessageManager::class)->read(Employee::LARA_ID, $session->id);

    expect($messages)->toHaveCount(1)
        ->and($messages[0]->content)->toBe(STOP_STALE_TEST_OUTPUT)
        ->and($messages[0]->runId)->toBe(STOP_STALE_TEST_RUN_ID)
        ->and($messages[0]->meta['stop_note'])->toBe('You stopped this run before it finished.')
        ->and($messages[0]->meta['provider_name'])->toBe(STOP_STALE_TEST_PROVIDER)
        ->and($messages[0]->meta['model'])->toBe(STOP_STALE_TEST_MODEL)
        ->and($messages[0]->meta['status'])->toBe(AiRunStatus::Cancelled->value)
        ->and($messages[0]->meta['latency_ms'])->toBeGreaterThan(0);
});
