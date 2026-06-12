<?php

use App\Base\Integration\Models\OutboundExchange;
use App\Modules\Core\AI\Enums\AiRunStatus;
use App\Modules\Core\AI\Enums\OperationStatus;
use App\Modules\Core\AI\Enums\OperationType;
use App\Modules\Core\AI\Enums\RunPhase;
use App\Modules\Core\AI\Jobs\RunChatTurnJob;
use App\Modules\Core\AI\Livewire\Chat;
use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Models\AiProviderModel;
use App\Modules\Core\AI\Models\AiRun;
use App\Modules\Core\AI\Models\OperationDispatch;
use App\Modules\Core\AI\Services\ControlPlane\WireLogger;
use App\Modules\Core\AI\Services\MessageManager;
use App\Modules\Core\AI\Services\SessionManager;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

const CHAT_VIEW_TEST_PROVIDER = 'stream-provider';
const CHAT_VIEW_TEST_MODEL = 'stream-model';

beforeEach(function (): void {
    config()->set('ai.workspace_path', storage_path('framework/testing/ai-chat-view-'.Str::random(16)));
    $this->wireLogPath = storage_path('framework/testing/ai-chat-view-wire-logs-'.Str::random(16));
});

afterEach(function (): void {
    $workspacePath = config('ai.workspace_path');

    if (is_string($workspacePath)) {
        File::deleteDirectory($workspacePath);
    }

    if (isset($this->wireLogPath) && is_string($this->wireLogPath)) {
        File::deleteDirectory($this->wireLogPath);
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
        ->toContain('repairAbandonedSelectedSession(runId)')
        ->toContain('selectedRunId: null')
        ->toContain('runRegistry: {}')
        ->toContain('ensureRunState(runId, patch = {})')
        ->toContain('teardownRunState(runId)')
        ->toContain('snapshotActiveRunState()')
        ->toContain('restoreRunState(runId)')
        ->toContain('activeTurnSummaries:')
        ->toContain('startSummaryPolling()')
        ->toContain('clearSummary($event.detail.sessionId, $event.detail?.runId || null)');
});

it('renders empty-session quick prompts for the active page url', function (): void {
    test()->actingAs(createChatViewFixture());

    Livewire::test(Chat::class)
        ->set('pageUrl', route('admin.employees.index'))
        ->assertSee('Create employee');
});

it('stores a client-captured active page snapshot by default', function (): void {
    Queue::fake();
    $user = createChatViewFixture();
    test()->actingAs($user);

    Livewire::test(Chat::class)
        ->set('pageUrl', route('admin.employees.index'))
        ->set('activePageSnapshot', [
            'forms' => [[
                'id' => 'page-fields',
                'fields' => [[
                    'name' => 'search',
                    'type' => 'search',
                    'value' => 'Alice',
                ]],
            ]],
        ])
        ->set('messageInput', 'What is on this page?')
        ->call('prepareStreamingRun');

    Queue::assertPushed(RunChatTurnJob::class);

    $turn = AiRun::query()->latest('created_at')->firstOrFail();
    $snapshot = data_get($turn->runtime_meta, 'page_context.snapshot');

    expect($snapshot['page']['route'])->toBe('admin.employees.index')
        ->and($snapshot['forms'][0]['fields'][0]['name'])->toBe('search')
        ->and($snapshot['forms'][0]['fields'][0]['value'])->toBe('Alice');
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

    $turn = AiRun::query()->create([
        'employee_id' => Employee::LARA_ID,
        'session_id' => $session->id,
        'acting_for_user_id' => $user->id,
        'status' => AiRunStatus::Queued,
        'current_phase' => RunPhase::WaitingForWorker,
        'current_label' => RunPhase::WaitingForWorker->label(),
    ]);

    Livewire::test(Chat::class)
        ->set('selectedSessionId', $session->id)
        ->assertSet('selectedModel', $compositeModelId)
        ->assertDispatched(
            'agent-chat-session-selected',
            sessionId: $session->id,
            activeTurnId: $turn->id,
            activeRunPhase: RunPhase::WaitingForWorker->value,
            activeTurnLabel: RunPhase::WaitingForWorker->label(),
        );
});

it('records a titling run, outbound exchange, and wire logs when wire logging is enabled', function (): void {
    config()->set('ai.wire_logging.enabled', true);

    $user = createChatViewFixture();
    test()->actingAs($user);

    $wireLogger = new class($this->wireLogPath) extends WireLogger
    {
        public function __construct(
            private readonly string $testWireLogPath,
        ) {}

        public function path(string $runId): string
        {
            return $this->testWireLogPath.'/'.$runId.'.jsonl';
        }
    };

    app()->instance(WireLogger::class, $wireLogger);

    Http::fake([
        'stream-provider.example.test/chat/completions' => Http::response([
            'choices' => [
                ['message' => ['role' => 'assistant', 'content' => '"Quarterly Revenue Summary"']],
            ],
            'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 4, 'total_tokens' => 16],
        ]),
    ]);

    $session = app(SessionManager::class)->create(Employee::LARA_ID);
    app(MessageManager::class)->appendUserMessage(Employee::LARA_ID, $session->id, 'Summarize the latest revenue discussion.');

    Livewire::test(Chat::class)->call('generateSessionTitle', $session->id);

    $updatedSession = app(SessionManager::class)->get(Employee::LARA_ID, $session->id);
    expect($updatedSession?->title)->toBe('Quarterly Revenue Summary');

    $run = AiRun::query()
        ->where('session_id', $session->id)
        ->latest('created_at')
        ->first();

    expect($run)->not->toBeNull()
        ->and($run->source)->toBe('simple_task')
        ->and($run->status->value)->toBe('succeeded');

    $exchange = OutboundExchange::query()
        ->where('system', 'ai')
        ->where('operation', 'ai.llm.chat')
        ->first();

    expect($exchange)->not->toBeNull()
        ->and($exchange->provider)->toBe(CHAT_VIEW_TEST_PROVIDER)
        ->and($exchange->protocol_operation)->toBe('POST /chat/completions')
        ->and($exchange->response_status)->toBe(200)
        ->and($exchange->request_body['value']['messages'])->not->toBeEmpty()
        ->and($exchange->response_body['value']['choices'][0]['message']['content'])->toBe('"Quarterly Revenue Summary"');

    $entries = $wireLogger->preview($run->id, 0, 500)['entries'];
    $entryTypes = array_column($entries, 'type');

    expect($entryTypes)
        ->toContain('llm.request')
        ->toContain('llm.response_status')
        ->toContain('llm.response_body')
        ->toContain('llm.complete');
});

it('titles the user request instead of Lara status updates', function (): void {
    $user = createChatViewFixture();
    test()->actingAs($user);

    Http::fake([
        'stream-provider.example.test/chat/completions' => Http::response([
            'choices' => [
                ['message' => ['role' => 'assistant', 'content' => 'Add Import Listings Icon']],
            ],
            'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 4, 'total_tokens' => 16],
        ]),
    ]);

    $session = app(SessionManager::class)->create(Employee::LARA_ID);
    app(MessageManager::class)->appendUserMessage(Employee::LARA_ID, $session->id, 'Add missing icon for the Import existing listings button.');
    app(MessageManager::class)->appendAssistantMessage(Employee::LARA_ID, $session->id, 'I have verified that there are no other references to heroicon-o-arrow-up-on-square.');

    Livewire::test(Chat::class)->call('generateSessionTitle', $session->id);

    $exchange = OutboundExchange::query()
        ->where('system', 'ai')
        ->where('operation', 'ai.llm.chat')
        ->latest('occurred_at')
        ->first();

    expect($exchange)->not->toBeNull();

    $messages = $exchange->request_body['value']['messages'];
    $messageText = collect($messages)->pluck('content')->implode("\n");

    expect($messageText)
        ->toContain('Add missing icon for the Import existing listings button.')
        ->not->toContain('I have verified that there are no other references');

    $updatedSession = app(SessionManager::class)->get(Employee::LARA_ID, $session->id);
    expect($updatedSession?->title)->toBe('Add Import Listings Icon');
});

it('shows feedback while suggesting a session title', function (): void {
    $user = createChatViewFixture();
    test()->actingAs($user);

    $session = app(SessionManager::class)->create(Employee::LARA_ID);

    $html = Livewire::test(Chat::class)
        ->call('startEditingTitle', $session->id)
        ->html();

    expect($html)
        ->toContain('wire:target="generateSessionTitle')
        ->toContain('Suggesting title…');
});

it('reports when a title cannot be suggested', function (): void {
    $user = createChatViewFixture();
    test()->actingAs($user);

    Http::fake([
        'stream-provider.example.test/chat/completions' => Http::response([
            'error' => ['message' => 'The engine is currently overloaded.'],
        ], 429),
    ]);

    $session = app(SessionManager::class)->create(Employee::LARA_ID);
    app(MessageManager::class)->appendUserMessage(Employee::LARA_ID, $session->id, 'Summarize this chat.');

    Livewire::test(Chat::class)
        ->call('generateSessionTitle', $session->id)
        ->assertSet('titleSuggestionSessionId', $session->id)
        ->assertSet('titleSuggestionTone', 'error')
        ->assertSet('titleSuggestionMessage', 'The engine is currently overloaded.');
});

it('reports that a conversation needs messages before suggesting a title', function (): void {
    $user = createChatViewFixture();
    test()->actingAs($user);

    $session = app(SessionManager::class)->create(Employee::LARA_ID);

    Livewire::test(Chat::class)
        ->call('generateSessionTitle', $session->id)
        ->assertSet('titleSuggestionSessionId', $session->id)
        ->assertSet('titleSuggestionTone', 'warning')
        ->assertSet('titleSuggestionMessage', 'Send a message before suggesting a title.');
});
