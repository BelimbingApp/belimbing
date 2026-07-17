<?php

use App\Modules\Core\AI\Livewire\Chat;
use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Models\AiProviderModel;
use App\Modules\Core\AI\Models\AiRun;
use App\Modules\Core\AI\Services\SessionManager;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function (): void {
    config()->set('ai.workspace_path', storage_path('framework/testing/ai-chat-effort-'.Str::random(16)));
});

afterEach(function (): void {
    $workspacePath = config('ai.workspace_path');

    if (is_string($workspacePath)) {
        File::deleteDirectory($workspacePath);
    }
});

function createChatEffortFixture(): array
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
        'name' => 'moonshotai',
        'display_name' => 'Moonshot AI',
        'base_url' => 'https://api.kimi.example.test/v1',
        'auth_type' => 'api_key',
        'credentials' => ['api_key' => 'test-key'],
        'connection_config' => [],
        'is_active' => true,
        'priority' => 1,
    ]);

    foreach (['kimi-k3', 'kimi-k2-0905-preview'] as $index => $modelId) {
        AiProviderModel::query()->create([
            'ai_provider_id' => $provider->id,
            'model_id' => $modelId,
            'is_active' => true,
            'is_default' => $index === 0,
        ]);
    }

    $user = User::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
    ]);

    return [$user, $provider];
}

test('selecting an effort persists it as a session execution-controls override', function (): void {
    [$user, $provider] = createChatEffortFixture();
    test()->actingAs($user);

    $component = Livewire::test(Chat::class)
        ->call('createSession')
        ->set('selectedModel', $provider->id.':::kimi-k3')
        ->set('selectedEffort', 'max');

    $sessionId = $component->get('selectedSessionId');

    expect($component->get('selectedEffort'))->toBe('max')
        ->and(app(SessionManager::class)->getExecutionControlsOverride(Employee::LARA_ID, $sessionId))
        ->toBe(['reasoning' => ['effort' => 'max']]);
});

test('efforts unsupported by the selected model are rejected', function (): void {
    [$user, $provider] = createChatEffortFixture();
    test()->actingAs($user);

    $component = Livewire::test(Chat::class)
        ->call('createSession')
        ->set('selectedModel', $provider->id.':::kimi-k3')
        ->set('selectedEffort', 'low');

    $sessionId = $component->get('selectedSessionId');

    expect($component->get('selectedEffort'))->toBeNull()
        ->and(app(SessionManager::class)->getExecutionControlsOverride(Employee::LARA_ID, $sessionId))
        ->toBeNull();
});

test('switching to a model without effort support clears the override', function (): void {
    [$user, $provider] = createChatEffortFixture();
    test()->actingAs($user);

    $component = Livewire::test(Chat::class)
        ->call('createSession')
        ->set('selectedModel', $provider->id.':::kimi-k3')
        ->set('selectedEffort', 'max')
        ->set('selectedModel', $provider->id.':::kimi-k2-0905-preview');

    $sessionId = $component->get('selectedSessionId');

    expect($component->get('selectedEffort'))->toBeNull()
        ->and(app(SessionManager::class)->getExecutionControlsOverride(Employee::LARA_ID, $sessionId))
        ->toBeNull();
});

test('the session effort override survives a session switch round-trip', function (): void {
    [$user, $provider] = createChatEffortFixture();
    test()->actingAs($user);

    $component = Livewire::test(Chat::class)
        ->call('createSession')
        ->set('selectedModel', $provider->id.':::kimi-k3')
        ->set('selectedEffort', 'max');

    $firstSessionId = $component->get('selectedSessionId');

    $component
        ->call('createSession')
        ->call('selectSession', $firstSessionId);

    expect($component->get('selectedSessionId'))->toBe($firstSessionId)
        ->and($component->get('selectedEffort'))->toBe('max');
});

test('a queued turn snapshots the effort selected with its model', function (): void {
    [$user, $provider] = createChatEffortFixture();
    test()->actingAs($user);

    $component = Livewire::test(Chat::class)
        ->call('createSession')
        ->set('selectedModel', $provider->id.':::kimi-k3')
        ->set('selectedEffort', 'max')
        ->set('messageInput', 'Use the selected model and effort.');

    $result = $component->instance()->prepareStreamingRun();
    $turn = AiRun::query()->findOrFail($result['runId']);

    expect($turn->runtime_meta['model_override'])->toBe($provider->id.':::kimi-k3')
        ->and($turn->runtime_meta['execution_controls_override'])->toBe([
            'reasoning' => ['effort' => 'max'],
        ]);
});
