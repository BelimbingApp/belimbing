<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\DTO\Session;
use App\Modules\Core\AI\Enums\RunPhase;
use App\Modules\Core\AI\Enums\AiRunStatus;
use App\Modules\Core\AI\Livewire\Chat;
use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Models\AiProviderModel;
use App\Modules\Core\AI\Models\AiRun;
use App\Modules\Core\AI\Services\SessionManager;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Livewire;

const CHAT_CONCURRENT_LIFECYCLE_PROVIDER = 'concurrency-lifecycle-provider';
const CHAT_CONCURRENT_LIFECYCLE_MODEL = 'concurrency-lifecycle-model';

beforeEach(function (): void {
    config()->set('ai.workspace_path', storage_path('framework/testing/ai-chat-concurrency-lifecycle-'.Str::random(16)));
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
function createChatConcurrentLifecycleFixture(): array
{
    Company::provisionLicensee('Test Company');
    Employee::provisionLara();

    $company = Company::query()->findOrFail(Company::LICENSEE_ID);

    $provider = AiProvider::query()->create([
        'company_id' => $company->id,
        'name' => CHAT_CONCURRENT_LIFECYCLE_PROVIDER,
        'display_name' => 'Concurrency Lifecycle Provider',
        'base_url' => 'https://concurrency-lifecycle-provider.example.test',
        'auth_type' => 'api_key',
        'credentials' => ['api_key' => 'test-key'],
        'connection_config' => [],
        'is_active' => true,
        'priority' => 1,
    ]);

    AiProviderModel::query()->create([
        'ai_provider_id' => $provider->id,
        'model_id' => CHAT_CONCURRENT_LIFECYCLE_MODEL,
        'is_active' => true,
        'is_default' => true,
    ]);

    return ['company' => $company];
}

function createChatConcurrentLifecycleUser(int $companyId): User
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

/**
 * @return array{0: Session, 1: Session}
 */
function createTwoDistinctLaraSessions(): array
{
    $base = now();

    Carbon::setTestNow($base);
    $sessionA = app(SessionManager::class)->create(Employee::LARA_ID);

    Carbon::setTestNow($base->copy()->addSecond());
    $sessionB = app(SessionManager::class)->create(Employee::LARA_ID);

    Carbon::setTestNow();

    return [$sessionA, $sessionB];
}

it('shows active turns from multiple sessions in the session panel model', function (): void {
    $fixture = createChatConcurrentLifecycleFixture();
    $user = createChatConcurrentLifecycleUser($fixture['company']->id);
    $this->actingAs($user);

    [$sessionA, $sessionB] = createTwoDistinctLaraSessions();

    $turnA = AiRun::query()->create([
        'employee_id' => Employee::LARA_ID,
        'session_id' => $sessionA->id,
        'acting_for_user_id' => $user->id,
        'status' => AiRunStatus::Running,
        'current_phase' => RunPhase::AwaitingLlm,
        'current_label' => 'Analyzing session A',
        'created_at' => now()->subMinutes(6),
        'started_at' => now()->subMinutes(5),
    ]);

    $turnB = AiRun::query()->create([
        'employee_id' => Employee::LARA_ID,
        'session_id' => $sessionB->id,
        'acting_for_user_id' => $user->id,
        'status' => AiRunStatus::Running,
        'current_phase' => RunPhase::RunningTool,
        'current_label' => 'Running bash',
        'created_at' => now()->subMinutes(4),
        'started_at' => now()->subMinutes(3),
    ]);

    $component = Livewire::test(Chat::class)
        ->set('selectedSessionId', $sessionA->id);

    $viewData = $component->instance()->render()->getData();

    $activeTurnsBySession = $viewData['activeTurnsBySession'] ?? [];
    $selectedSessionActiveTurn = $viewData['selectedSessionActiveTurn'] ?? null;

    expect($activeTurnsBySession)->toHaveCount(2)
        ->and($activeTurnsBySession[$sessionA->id]['runId'])->toBe($turnA->id)
        ->and($activeTurnsBySession[$sessionA->id]['label'])->toBe('Analyzing session A')
        ->and($activeTurnsBySession[$sessionB->id]['runId'])->toBe($turnB->id)
        ->and($activeTurnsBySession[$sessionB->id]['label'])->toBe('Running bash')
        ->and($selectedSessionActiveTurn['runId'])->toBe($turnA->id);
});

it('stopping one stale turn does not cancel another active session turn', function (): void {
    $fixture = createChatConcurrentLifecycleFixture();
    $user = createChatConcurrentLifecycleUser($fixture['company']->id);
    $this->actingAs($user);

    [$sessionA, $sessionB] = createTwoDistinctLaraSessions();

    $turnToStop = AiRun::query()->create([
        'employee_id' => Employee::LARA_ID,
        'session_id' => $sessionA->id,
        'acting_for_user_id' => $user->id,
        'status' => AiRunStatus::Running,
        'current_phase' => RunPhase::AwaitingLlm,
        'current_label' => 'Long running turn',
        'created_at' => now()->subMinutes(80),
        'started_at' => now()->subMinutes(70),
    ]);

    $turnToKeep = AiRun::query()->create([
        'employee_id' => Employee::LARA_ID,
        'session_id' => $sessionB->id,
        'acting_for_user_id' => $user->id,
        'status' => AiRunStatus::Running,
        'current_phase' => RunPhase::RunningTool,
        'current_label' => 'Still active',
        'created_at' => now()->subMinutes(2),
        'started_at' => now()->subMinutes(1),
    ]);

    Livewire::test(Chat::class)->call('cancelActiveTurn', $turnToStop->id);

    $turnToStop->refresh();
    $turnToKeep->refresh();

    $turnStoppedWithTerminalEvent = $turnToStop->events()
        ->where('event_type', 'run.cancelled')
        ->exists();

    expect($turnToStop->status)->toBe(AiRunStatus::Cancelled)
        ->and($turnToStop->current_phase)->toBe(RunPhase::Cancelled)
        ->and($turnStoppedWithTerminalEvent)->toBeTrue();

    expect($turnToKeep->status)->toBe(AiRunStatus::Running)
        ->and($turnToKeep->cancel_requested_at)->toBeNull();
});

it('keeps other session active state when one session reaches terminal status', function (): void {
    $fixture = createChatConcurrentLifecycleFixture();
    $user = createChatConcurrentLifecycleUser($fixture['company']->id);
    $this->actingAs($user);

    [$sessionA, $sessionB] = createTwoDistinctLaraSessions();

    $completedTurn = AiRun::query()->create([
        'employee_id' => Employee::LARA_ID,
        'session_id' => $sessionA->id,
        'acting_for_user_id' => $user->id,
        'status' => AiRunStatus::Succeeded,
        'current_phase' => RunPhase::Finalizing,
        'current_label' => 'Done',
        'created_at' => now()->subMinutes(8),
        'started_at' => now()->subMinutes(7),
        'finished_at' => now()->subMinutes(6),
    ]);

    $runningTurn = AiRun::query()->create([
        'employee_id' => Employee::LARA_ID,
        'session_id' => $sessionB->id,
        'acting_for_user_id' => $user->id,
        'status' => AiRunStatus::Running,
        'current_phase' => RunPhase::AwaitingLlm,
        'current_label' => 'Working on session B',
        'created_at' => now()->subMinutes(4),
        'started_at' => now()->subMinutes(3),
    ]);

    $component = Livewire::test(Chat::class)
        ->set('selectedSessionId', $sessionA->id);

    $viewData = $component->instance()->render()->getData();
    $activeTurnsBySession = $viewData['activeTurnsBySession'] ?? [];

    expect($activeTurnsBySession)->toHaveCount(1)
        ->and(isset($activeTurnsBySession[$sessionA->id]))->toBeFalse()
        ->and($activeTurnsBySession[$sessionB->id]['runId'])->toBe($runningTurn->id);

    expect($completedTurn->status)->toBe(AiRunStatus::Succeeded);
});
