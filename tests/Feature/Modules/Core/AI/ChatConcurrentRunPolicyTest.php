<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\Enums\TurnPhase;
use App\Modules\Core\AI\Enums\TurnStatus;
use App\Modules\Core\AI\Livewire\Chat;
use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Models\AiProviderModel;
use App\Modules\Core\AI\Models\ChatTurn;
use App\Modules\Core\AI\Services\MessageManager;
use App\Modules\Core\AI\Services\SessionManager;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Livewire;

const CHAT_CONCURRENCY_TEST_PROVIDER = 'concurrency-provider';
const CHAT_CONCURRENCY_TEST_MODEL = 'concurrency-model';

beforeEach(function (): void {
    config()->set('ai.workspace_path', storage_path('framework/testing/ai-chat-concurrency-'.Str::random(16)));
});

afterEach(function (): void {
    $workspacePath = config('ai.workspace_path');

    if (is_string($workspacePath)) {
        File::deleteDirectory($workspacePath);
    }
});

/**
 * @return array{company: Company}
 */
function createChatConcurrencyFixture(): array
{
    Company::provisionLicensee('Test Company');
    Employee::provisionLara();

    $company = Company::query()->findOrFail(Company::LICENSEE_ID);

    $provider = AiProvider::query()->create([
        'company_id' => $company->id,
        'name' => CHAT_CONCURRENCY_TEST_PROVIDER,
        'display_name' => 'Concurrency Provider',
        'base_url' => 'https://concurrency-provider.example.test',
        'auth_type' => 'api_key',
        'credentials' => ['api_key' => 'test-key'],
        'connection_config' => [],
        'is_active' => true,
        'priority' => 1,
    ]);

    AiProviderModel::query()->create([
        'ai_provider_id' => $provider->id,
        'model_id' => CHAT_CONCURRENCY_TEST_MODEL,
        'is_active' => true,
        'is_default' => true,
    ]);

    return ['company' => $company];
}

function createChatConcurrencyUser(int $companyId): User
{
    $employee = Employee::factory()->create([
        'company_id' => $companyId,
        'status' => 'active',
    ]);

    return User::factory()->create([
        'company_id' => $companyId,
        'employee_id' => $employee->id,
    ]);
}

it('rejects same-session submit when an active turn already exists', function (): void {
    $fixture = createChatConcurrencyFixture();
    $user = createChatConcurrencyUser($fixture['company']->id);
    $this->actingAs($user);

    $session = app(SessionManager::class)->create(Employee::LARA_ID);

    $activeTurn = ChatTurn::query()->create([
        'employee_id' => Employee::LARA_ID,
        'session_id' => $session->id,
        'acting_for_user_id' => $user->id,
        'status' => TurnStatus::Running,
        'current_phase' => TurnPhase::Thinking,
        'current_label' => 'Analyzing context…',
        'created_at' => now()->subMinutes(3),
        'started_at' => now()->subMinutes(2),
    ])->refresh();

    $component = Livewire::test(Chat::class)
        ->set('selectedSessionId', $session->id)
        ->set('messageInput', 'Can you continue?');

    $result = $component->instance()->prepareStreamingRun();

    expect($result)->not->toBeNull()
        ->and($result['status'])->toBe('session_busy')
        ->and($result['turnId'])->toBe($activeTurn->id)
        ->and($result['session_id'])->toBe($session->id)
        ->and($result['phase'])->toBe(TurnPhase::Thinking->value)
        ->and($result['label'])->toBe('Analyzing context…')
        ->and($result['streamUrl'] ?? null)->toBeNull()
        ->and($component->instance()->messageInput)->toBe('Can you continue?');

    expect(ChatTurn::query()
        ->where('session_id', $session->id)
        ->where('acting_for_user_id', $user->id)
        ->count())->toBe(1);

    expect(app(MessageManager::class)->read(Employee::LARA_ID, $session->id))->toBe([]);
});

it('allows a different user to start a run even when another user has an active turn with the same session id', function (): void {
    $fixture = createChatConcurrencyFixture();
    $userA = createChatConcurrencyUser($fixture['company']->id);
    $userB = createChatConcurrencyUser($fixture['company']->id);

    $this->actingAs($userB);
    $session = app(SessionManager::class)->create(Employee::LARA_ID);

    ChatTurn::query()->create([
        'employee_id' => Employee::LARA_ID,
        'session_id' => $session->id,
        'acting_for_user_id' => $userA->id,
        'status' => TurnStatus::Running,
        'current_phase' => TurnPhase::Thinking,
        'current_label' => 'Busy elsewhere',
    ]);

    $component = Livewire::test(Chat::class)
        ->set('selectedSessionId', $session->id)
        ->set('messageInput', 'Run for user B');

    $result = $component->instance()->prepareStreamingRun();

    expect($result)->not->toBeNull()
        ->and($result['status'])->toBe('started')
        ->and($result['turnId'])->toBeString()
        ->and($result['turnId'])->not->toBeEmpty()
        ->and($result['streamUrl'])->toBeString()
        ->and($result['session_id'])->toBe($session->id);

    expect(ChatTurn::query()
        ->where('session_id', $session->id)
        ->where('acting_for_user_id', $userB->id)
        ->count())->toBe(1);
});

it('returns durable timer metadata when starting a new turn', function (): void {
    $fixture = createChatConcurrencyFixture();
    $user = createChatConcurrencyUser($fixture['company']->id);
    $this->actingAs($user);

    $session = app(SessionManager::class)->create(Employee::LARA_ID);

    $component = Livewire::test(Chat::class)
        ->set('selectedSessionId', $session->id)
        ->set('messageInput', 'Start a fresh run');

    $result = $component->instance()->prepareStreamingRun();

    expect($result)->not->toBeNull()
        ->and($result['status'])->toBe('started')
        ->and($result['phase'])->toBe(TurnPhase::WaitingForWorker->value)
        ->and($result['label'])->toBe(TurnPhase::WaitingForWorker->label())
        ->and($result['created_at'])->toBeString()
        ->and($result['timer_anchor_at'])->toBe($result['created_at'])
        ->and($result['replayUrl'])->toContain('/api/ai/chat/turns/')
        ->and($result['streamUrl'])->toContain('/api/ai/chat/turns/');

    $messages = app(MessageManager::class)->read(Employee::LARA_ID, $session->id);

    expect($messages)->toHaveCount(1)
        ->and($messages[0]->role)->toBe('user')
        ->and($messages[0]->content)->toBe('Start a fresh run');
});
