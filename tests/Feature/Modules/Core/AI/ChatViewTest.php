<?php

use App\Modules\Core\AI\Enums\OperationStatus;
use App\Modules\Core\AI\Enums\OperationType;
use App\Modules\Core\AI\Enums\TurnPhase;
use App\Modules\Core\AI\Enums\TurnStatus;
use App\Modules\Core\AI\Livewire\Chat;
use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Models\AiProviderModel;
use App\Modules\Core\AI\Models\ChatTurn;
use App\Modules\Core\AI\Models\OperationDispatch;
use App\Modules\Core\AI\Services\SessionManager;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Livewire;

const CHAT_VIEW_TEST_PROVIDER = 'stream-provider';
const CHAT_VIEW_TEST_MODEL = 'stream-model';

beforeEach(function (): void {
    config()->set('ai.workspace_path', storage_path('framework/testing/ai-chat-view-'.Str::random(16)));
});

afterEach(function (): void {
    $workspacePath = config('ai.workspace_path');

    if (is_string($workspacePath)) {
        File::deleteDirectory($workspacePath);
    }
});

function createChatViewFixture(): User
{
    Company::provisionLicensee('Test Company');
    Employee::provisionLara();

    $company = Company::query()->findOrFail(Company::LICENSEE_ID);
    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'status' => 'active',
    ]);

    $provider = AiProvider::query()->create([
        'company_id' => $company->id,
        'name' => CHAT_VIEW_TEST_PROVIDER,
        'display_name' => 'Stream Provider',
        'base_url' => 'https://stream-provider.example.test',
        'auth_type' => 'api_key',
        'credentials' => ['api_key' => 'test-key'],
        'connection_config' => [],
        'is_active' => true,
        'priority' => 1,
    ]);

    AiProviderModel::query()->create([
        'ai_provider_id' => $provider->id,
        'model_id' => CHAT_VIEW_TEST_MODEL,
        'is_active' => true,
        'is_default' => true,
    ]);

    return User::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
    ]);
}

it('renders the streaming console as a named alpine controller', function (): void {
    test()->actingAs(createChatViewFixture());

    $html = Livewire::test(Chat::class)->html();

    expect($html)
        ->toContain('x-data="agentChatStream({')
        ->toContain('Alpine.data(&#039;agentChatStream&#039;')
        ->toContain('phaseLabels:')
        ->toContain('labelForPhase(phase, fallback = null)')
        ->toContain('const text = payload.delta || payload.text ||')
        ->toContain('onServerTurnReady($event.detail || {})')
        ->toContain('this.$wire.finalizeStreamingRun(finalizedTurnId, finalizedSessionId)')
        ->toContain('repairAbandonedSelectedSession(turnId)')
        ->toContain('selectedTurnId: null')
        ->toContain('turnRegistry: {}')
        ->toContain('ensureTurnState(turnId, patch = {})')
        ->toContain('teardownTurnState(turnId)')
        ->toContain('snapshotActiveTurnState()')
        ->toContain('restoreTurnState(turnId)')
        ->toContain('activeTurnSummaries:')
        ->toContain('startSummaryPolling()')
        ->toContain('clearSummary($event.detail.sessionId, $event.detail?.turnId || null)');
});

it('polls the chat view while the selected Lara session has pending delegated work', function (): void {
    $user = createChatViewFixture();
    test()->actingAs($user);

    $session = app(SessionManager::class)->create(Employee::LARA_ID);

    OperationDispatch::unguarded(fn () => OperationDispatch::query()->create([
        'id' => 'op_chat_pending_delegate',
        'operation_type' => OperationType::AgentTask,
        'employee_id' => Employee::LARA_ID,
        'acting_for_user_id' => $user->id,
        'task' => 'Investigate the latest provider updates',
        'status' => OperationStatus::Queued,
        'meta' => [
            'session_id' => $session->id,
            'task_profile' => 'research',
            'task_profile_label' => 'Research',
        ],
    ]));

    $html = Livewire::test(Chat::class)
        ->set('selectedSessionId', $session->id)
        ->html();

    expect($html)->toContain('wire:poll.2s');
});

it('hydrates the selected model from the initially selected session override', function (): void {
    $user = createChatViewFixture();
    test()->actingAs($user);

    $session = app(SessionManager::class)->create(Employee::LARA_ID);
    $provider = AiProvider::query()->where('name', CHAT_VIEW_TEST_PROVIDER)->firstOrFail();
    $compositeModelId = $provider->id.':::'.CHAT_VIEW_TEST_MODEL;

    app(SessionManager::class)->updateModelOverride(Employee::LARA_ID, $session->id, $compositeModelId);

    Livewire::test(Chat::class)
        ->assertSet('selectedSessionId', $session->id)
        ->assertSet('selectedModel', $compositeModelId);
});

it('re-hydrates session override and active turn state when selectedSessionId is restored from the client', function (): void {
    $user = createChatViewFixture();
    test()->actingAs($user);

    $session = app(SessionManager::class)->create(Employee::LARA_ID);
    $provider = AiProvider::query()->where('name', CHAT_VIEW_TEST_PROVIDER)->firstOrFail();
    $compositeModelId = $provider->id.':::'.CHAT_VIEW_TEST_MODEL;

    app(SessionManager::class)->updateModelOverride(Employee::LARA_ID, $session->id, $compositeModelId);

    $turn = ChatTurn::query()->create([
        'employee_id' => Employee::LARA_ID,
        'session_id' => $session->id,
        'acting_for_user_id' => $user->id,
        'status' => TurnStatus::Queued,
        'current_phase' => TurnPhase::WaitingForWorker,
        'current_label' => TurnPhase::WaitingForWorker->label(),
    ]);

    Livewire::test(Chat::class)
        ->set('selectedSessionId', $session->id)
        ->assertSet('selectedModel', $compositeModelId)
        ->assertDispatched(
            'agent-chat-session-selected',
            sessionId: $session->id,
            activeTurnId: $turn->id,
            activeTurnPhase: TurnPhase::WaitingForWorker->value,
            activeTurnLabel: TurnPhase::WaitingForWorker->label(),
        );
});
