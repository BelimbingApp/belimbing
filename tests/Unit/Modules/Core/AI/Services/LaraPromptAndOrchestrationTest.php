<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Models\OperationDispatch;
use App\Modules\Core\AI\Services\LaraOrchestrationService;
use App\Modules\Core\AI\Services\LaraPromptFactory;
use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Collection;
use Tests\Support\CreatesLaraFixtures;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class, CreatesLaraFixtures::class);

const CODE_WORKER = 'Code Worker';

function createLaraOrchestrationFixture(object $testCase): array
{
    $fixture = $testCase->createLaraFixture();
    $company = $fixture['company'];
    $supervisor = $fixture['employee'];

    foreach ([
        [
            'full_name' => CODE_WORKER,
            'short_name' => CODE_WORKER,
            'designation' => 'Code Engineer',
            'job_description' => 'Builds modules and writes PHP code.',
        ],
        [
            'full_name' => 'Data Worker',
            'short_name' => 'Data Worker',
            'designation' => 'Data Specialist',
            'job_description' => 'Handles migrations and data imports.',
        ],
    ] as $agent) {
        Employee::factory()->create([
            'company_id' => $company->id,
            'employee_type' => 'agent',
            'supervisor_id' => $supervisor->id,
            'status' => 'active',
            ...$agent,
        ]);
    }

    AiProvider::query()->create([
        'company_id' => $company->id,
        'name' => 'openai',
        'display_name' => 'OpenAI',
        'base_url' => 'https://api.openai.com/v1',
        'auth_type' => 'api_key',
        'credentials' => ['api_key' => 'sk-test'],
        'connection_config' => [],
        'is_active' => true,
        'priority' => 1,
        'created_by' => $supervisor->id,
    ]);

    return $fixture;
}

it('builds Lara prompt with runtime context and delegation metadata', function (): void {
    $fixture = createLaraOrchestrationFixture($this);
    $this->actingAs($fixture['user']);

    $prompt = app(LaraPromptFactory::class)->buildForCurrentUser();

    expect($prompt)->toContain('You are Lara Belimbing')
        ->and($prompt)->toContain('"modules"')
        ->and($prompt)->toContain('"providers"')
        ->and($prompt)->toContain('"knowledge"')
        ->and($prompt)->toContain('OpenAI')
        ->and($prompt)->toContain('"/go <target>"')
        ->and($prompt)->toContain('"/models <filter>"')
        ->and($prompt)->toContain('"/guide <topic>"')
        ->and($prompt)->toContain(CODE_WORKER);
});

it('appends configured Lara prompt extension as additive guidance', function (): void {
    $fixture = createLaraOrchestrationFixture($this);
    $this->actingAs($fixture['user']);

    $extensionRelativePath = 'storage/framework/testing/lara_extension_test.md';
    $extensionPath = base_path($extensionRelativePath);
    $extensionDirectory = dirname($extensionPath);

    if (! is_dir($extensionDirectory)) {
        mkdir($extensionDirectory, 0755, true);
    }

    file_put_contents($extensionPath, '- Prefer short bullet answers for operational checklists.');
    config()->set('ai.lara.prompt.extension_path', $extensionRelativePath);

    try {
        $prompt = app(LaraPromptFactory::class)->buildForCurrentUser();

        expect($prompt)->toContain('You are Lara Belimbing')
            ->and($prompt)->toContain('Prompt extension policy (append-only):')
            ->and($prompt)->toContain('Prefer short bullet answers for operational checklists.');
    } finally {
        if (is_file($extensionPath)) {
            unlink($extensionPath);
        }
        config()->set('ai.lara.prompt.extension_path', null);
    }
});

it('returns null when message is not a delegation command', function (): void {
    $fixture = createLaraOrchestrationFixture($this);
    $this->actingAs($fixture['user']);

    $service = app(LaraOrchestrationService::class);

    expect($service->dispatchFromMessage('Hello Lara'))->toBeNull();
});

it('returns Belimbing references when user asks for a guide command', function (): void {
    $fixture = createLaraOrchestrationFixture($this);
    $this->actingAs($fixture['user']);

    $service = app(LaraOrchestrationService::class);
    $result = $service->dispatchFromMessage('/guide authorization');

    expect($result)->not->toBeNull()
        ->and($result['meta']['orchestration']['status'])->toBe('guide_references')
        ->and($result['meta']['orchestration']['topic'])->toBe('authorization')
        ->and($result['assistant_content'])->toContain('docs/architecture/authorization.md');
});

it('returns usage guidance for empty models command', function (): void {
    $fixture = createLaraOrchestrationFixture($this);
    $this->actingAs($fixture['user']);

    $service = app(LaraOrchestrationService::class);
    $result = $service->dispatchFromMessage('/models');

    expect($result)->not->toBeNull()
        ->and($result['meta']['orchestration']['status'])->toBe('invalid_models_command')
        ->and($result['assistant_content'])->toContain('/models <filter>');
});

it('returns filter error for invalid models command syntax', function (): void {
    $fixture = createLaraOrchestrationFixture($this);
    $this->actingAs($fixture['user']);

    $service = app(LaraOrchestrationService::class);
    $result = $service->dispatchFromMessage('/models reasoning:true AND ???');

    expect($result)->not->toBeNull()
        ->and($result['meta']['orchestration']['status'])->toBe('invalid_models_filter');
});

it('returns navigation metadata for /go command', function (): void {
    $fixture = createLaraOrchestrationFixture($this);
    $this->actingAs($fixture['user']);

    $this->app->instance(AuthorizationService::class, new class implements AuthorizationService
    {
        public function can(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): AuthorizationDecision
        {
            return AuthorizationDecision::allow(['test']);
        }

        public function authorize(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): void {}

        public function filterAllowed(Actor $actor, string $capability, iterable $resources, array $context = []): Collection
        {
            return collect($resources);
        }
    });

    $service = app(LaraOrchestrationService::class);
    $result = $service->dispatchFromMessage('/go providers');

    expect($result)->not->toBeNull()
        ->and($result['meta']['orchestration']['status'])->toBe('navigation')
        ->and($result['meta']['orchestration']['navigation']['strategy'])->toBe('js_go_to_url')
        ->and($result['meta']['orchestration']['navigation']['url'])->toBe('/admin/ai/providers');
});

it('returns unknown target status for unsupported /go target', function (): void {
    $fixture = createLaraOrchestrationFixture($this);
    $this->actingAs($fixture['user']);

    $service = app(LaraOrchestrationService::class);
    $result = $service->dispatchFromMessage('/go unknown-page');

    expect($result)->not->toBeNull()
        ->and($result['meta']['orchestration']['status'])->toBe('unknown_navigation_target');
});

it('queues delegation to the best matched agent', function (): void {
    $fixture = createLaraOrchestrationFixture($this);
    $this->actingAs($fixture['user']);

    $service = app(LaraOrchestrationService::class);
    $result = $service->dispatchFromMessage('/delegate build a PHP module with tests', '20260413-010101');
    $dispatch = OperationDispatch::query()->find($result['meta']['orchestration']['dispatch_id']);

    expect($result)->not->toBeNull()
        ->and($result['meta']['orchestration']['status'])->toBe('queued')
        ->and($result['meta']['orchestration']['selected_agent']['agent_name'])->toBe(CODE_WORKER)
        ->and($result['meta']['orchestration']['dispatch_id'])->toStartWith('op_')
        ->and(data_get($dispatch?->meta, 'session_id'))->toBe('20260413-010101');
});

it('falls back to Lara coding task profile when no delegated agents are available', function (): void {
    $fixture = $this->createLaraFixture();
    $this->actingAs($fixture['user']);

    $service = app(LaraOrchestrationService::class);
    $result = $service->dispatchFromMessage('/delegate create dashboard page', '20260413-020202');
    $dispatch = OperationDispatch::query()->find($result['meta']['orchestration']['dispatch_id']);

    expect($result)->not->toBeNull()
        ->and($result['meta']['orchestration']['status'])->toBe('queued')
        ->and($result['meta']['orchestration']['selected_task_profile']['task_key'])->toBe('coding')
        ->and($result['meta']['orchestration']['dispatch_id'])->toStartWith('op_')
        ->and(data_get($dispatch?->meta, 'session_id'))->toBe('20260413-020202');
});

it('routes research-oriented delegation to Lara research task profile when no delegated agents are available', function (): void {
    $fixture = $this->createLaraFixture();
    $this->actingAs($fixture['user']);

    $service = app(LaraOrchestrationService::class);
    $result = $service->dispatchFromMessage('/delegate investigate the latest OpenAI documentation updates');

    expect($result)->not->toBeNull()
        ->and($result['meta']['orchestration']['status'])->toBe('queued')
        ->and($result['meta']['orchestration']['selected_task_profile']['task_key'])->toBe('research')
        ->and($result['meta']['orchestration']['dispatch_id'])->toStartWith('op_');
});
