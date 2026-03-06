<?php

use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Services\LaraOrchestrationService;
use App\Modules\Core\AI\Services\LaraPromptFactory;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

/**
 * @return array{company: Company, user: User}
 */
function createLaraOrchestrationFixture(): array
{
    $company = Company::factory()->create();

    $supervisor = Employee::factory()->create([
        'company_id' => $company->id,
        'status' => 'active',
    ]);

    $user = User::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $supervisor->id,
    ]);

    Employee::factory()->create([
        'company_id' => $company->id,
        'employee_type' => 'digital_worker',
        'supervisor_id' => $supervisor->id,
        'full_name' => 'Code Worker',
        'short_name' => 'Code Worker',
        'designation' => 'Code Engineer',
        'job_description' => 'Builds modules and writes PHP code.',
        'status' => 'active',
    ]);

    Employee::factory()->create([
        'company_id' => $company->id,
        'employee_type' => 'digital_worker',
        'supervisor_id' => $supervisor->id,
        'full_name' => 'Data Worker',
        'short_name' => 'Data Worker',
        'designation' => 'Data Specialist',
        'job_description' => 'Handles migrations and data imports.',
        'status' => 'active',
    ]);

    AiProvider::query()->create([
        'company_id' => $company->id,
        'name' => 'openai',
        'display_name' => 'OpenAI',
        'base_url' => 'https://api.openai.com/v1',
        'api_key' => 'sk-test',
        'is_active' => true,
        'priority' => 1,
        'created_by' => $supervisor->id,
    ]);

    return [
        'company' => $company,
        'user' => $user,
    ];
}

it('builds Lara prompt with runtime context and delegation metadata', function (): void {
    $fixture = createLaraOrchestrationFixture();
    $this->actingAs($fixture['user']);

    $prompt = app(LaraPromptFactory::class)->buildForCurrentUser();

    expect($prompt)->toContain('You are Lara Belimbing')
        ->and($prompt)->toContain('"modules"')
        ->and($prompt)->toContain('"providers"')
        ->and($prompt)->toContain('OpenAI')
        ->and($prompt)->toContain('"command": "/delegate <task>"')
        ->and($prompt)->toContain('Code Worker');
});

it('appends configured Lara prompt extension as additive guidance', function (): void {
    $fixture = createLaraOrchestrationFixture();
    $this->actingAs($fixture['user']);

    $extensionRelativePath = 'storage/app/testing/lara_extension_test.md';
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
    $fixture = createLaraOrchestrationFixture();
    $this->actingAs($fixture['user']);

    $service = app(LaraOrchestrationService::class);

    expect($service->dispatchFromMessage('Hello Lara'))->toBeNull();
});

it('queues delegation to the best matched worker', function (): void {
    $fixture = createLaraOrchestrationFixture();
    $this->actingAs($fixture['user']);

    $service = app(LaraOrchestrationService::class);
    $result = $service->dispatchFromMessage('/delegate build a PHP module with tests');

    expect($result)->not->toBeNull()
        ->and($result['meta']['orchestration']['status'])->toBe('queued')
        ->and($result['meta']['orchestration']['selected_worker']['name'])->toBe('Code Worker')
        ->and($result['meta']['orchestration']['dispatch']['employee_name'])->toBe('Code Worker')
        ->and($result['meta']['orchestration']['dispatch']['task'])->toBe('build a PHP module with tests');
});

it('returns no_workers status when no delegated workers are available', function (): void {
    $company = Company::factory()->create();
    $supervisor = Employee::factory()->create([
        'company_id' => $company->id,
        'status' => 'active',
    ]);

    $user = User::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $supervisor->id,
    ]);

    $this->actingAs($user);

    $service = app(LaraOrchestrationService::class);
    $result = $service->dispatchFromMessage('/delegate create dashboard page');

    expect($result)->not->toBeNull()
        ->and($result['meta']['orchestration']['status'])->toBe('no_workers');
});
