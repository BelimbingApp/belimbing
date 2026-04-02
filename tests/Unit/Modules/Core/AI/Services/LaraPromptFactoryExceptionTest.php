<?php

use App\Base\Foundation\Enums\BlbErrorCode;
use App\Base\Foundation\Exceptions\BlbConfigurationException;
use App\Base\Foundation\Exceptions\BlbIntegrationException;
use App\Modules\Core\AI\DTO\WorkspaceFileEntry;
use App\Modules\Core\AI\DTO\WorkspaceManifest;
use App\Modules\Core\AI\DTO\WorkspaceValidationResult;
use App\Modules\Core\AI\Enums\WorkspaceFileSlot;
use App\Modules\Core\AI\Services\LaraContextProvider;
use App\Modules\Core\AI\Services\LaraPromptFactory;
use App\Modules\Core\AI\Services\Orchestration\AgentCapabilityCatalog;
use App\Modules\Core\AI\Services\Workspace\PromptPackageFactory;
use App\Modules\Core\AI\Services\Workspace\PromptRenderer;
use App\Modules\Core\AI\Services\Workspace\WorkspaceResolver;
use App\Modules\Core\AI\Services\Workspace\WorkspaceValidator;
use App\Modules\Core\Employee\Models\Employee;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Build a minimal valid WorkspaceManifest with system_prompt resolved from framework.
 */
function validLaraManifest(): WorkspaceManifest
{
    $systemPromptPath = app_path('Modules/Core/AI/Resources/lara/system_prompt.md');

    return new WorkspaceManifest(
        employeeId: Employee::LARA_ID,
        workspacePath: '/tmp/workspace/'.Employee::LARA_ID,
        isSystemAgent: true,
        frameworkResourcePath: app_path('Modules/Core/AI/Resources/lara'),
        files: [
            WorkspaceFileEntry::found(WorkspaceFileSlot::SystemPrompt, $systemPromptPath, 'framework'),
            WorkspaceFileEntry::missing(WorkspaceFileSlot::Operator),
            WorkspaceFileEntry::missing(WorkspaceFileSlot::Tools),
            WorkspaceFileEntry::missing(WorkspaceFileSlot::Extension),
            WorkspaceFileEntry::missing(WorkspaceFileSlot::Memory),
        ],
    );
}

it('throws integration exception when Lara runtime context cannot be encoded', function (): void {
    $resource = fopen('php://memory', 'r');
    expect($resource)->not->toBeFalse();

    $manifest = validLaraManifest();
    $validation = new WorkspaceValidationResult(
        valid: true,
        errors: [],
        warnings: [],
        loadOrder: [WorkspaceFileSlot::SystemPrompt],
    );

    $resolver = Mockery::mock(WorkspaceResolver::class);
    $resolver->shouldReceive('resolve')->with(Employee::LARA_ID)->andReturn($manifest);

    $validator = Mockery::mock(WorkspaceValidator::class);
    $validator->shouldReceive('validate')->with($manifest)->andReturn($validation);

    $contextProvider = Mockery::mock(LaraContextProvider::class);
    $contextProvider->shouldReceive('contextForCurrentUser')->once()->andReturn([
        'broken' => $resource,
    ]);

    $capabilityCatalog = Mockery::mock(AgentCapabilityCatalog::class);
    $capabilityCatalog->shouldReceive('delegableDescriptorsForCurrentUser')->once()->andReturn([]);

    $factory = new LaraPromptFactory(
        $contextProvider,
        $capabilityCatalog,
        $resolver,
        $validator,
        new PromptPackageFactory,
        new PromptRenderer,
    );

    expect(fn () => $factory->buildForCurrentUser())
        ->toThrow(function (BlbIntegrationException $exception): void {
            expect($exception->reasonCode)->toBe(BlbErrorCode::LARA_PROMPT_CONTEXT_ENCODE_FAILED);
        });

    fclose($resource);
});

it('throws configuration exception when Lara workspace validation fails', function (): void {
    $manifest = validLaraManifest();
    $validation = new WorkspaceValidationResult(
        valid: false,
        errors: ['Required workspace file missing: system_prompt.md (slot: system_prompt)'],
        warnings: [],
        loadOrder: [],
    );

    $resolver = Mockery::mock(WorkspaceResolver::class);
    $resolver->shouldReceive('resolve')->with(Employee::LARA_ID)->andReturn($manifest);

    $validator = Mockery::mock(WorkspaceValidator::class);
    $validator->shouldReceive('validate')->with($manifest)->andReturn($validation);

    $contextProvider = Mockery::mock(LaraContextProvider::class);
    $capabilityCatalog = Mockery::mock(AgentCapabilityCatalog::class);

    $factory = new LaraPromptFactory(
        $contextProvider,
        $capabilityCatalog,
        $resolver,
        $validator,
        new PromptPackageFactory,
        new PromptRenderer,
    );

    expect(fn () => $factory->buildForCurrentUser())
        ->toThrow(function (BlbConfigurationException $exception): void {
            expect($exception->reasonCode)->toBe(BlbErrorCode::WORKSPACE_VALIDATION_FAILED)
                ->and($exception->context['errors'])->toBeArray()
                ->and($exception->context['errors'])->not->toBeEmpty();
        });
});

it('gracefully skips missing legacy extension path without throwing', function (): void {
    config()->set('ai.lara.prompt.extension_path', 'storage/app/testing/missing_lara_extension.md');

    try {
        $manifest = validLaraManifest();
        $validation = new WorkspaceValidationResult(
            valid: true,
            errors: [],
            warnings: [],
            loadOrder: [WorkspaceFileSlot::SystemPrompt],
        );

        $resolver = Mockery::mock(WorkspaceResolver::class);
        $resolver->shouldReceive('resolve')->with(Employee::LARA_ID)->andReturn($manifest);

        $validator = Mockery::mock(WorkspaceValidator::class);
        $validator->shouldReceive('validate')->with($manifest)->andReturn($validation);

        $contextProvider = Mockery::mock(LaraContextProvider::class);
        $contextProvider->shouldReceive('contextForCurrentUser')->once()->andReturn([
            'app' => ['name' => 'Belimbing'],
        ]);

        $capabilityCatalog = Mockery::mock(AgentCapabilityCatalog::class);
        $capabilityCatalog->shouldReceive('delegableDescriptorsForCurrentUser')->once()->andReturn([]);

        $factory = new LaraPromptFactory(
            $contextProvider,
            $capabilityCatalog,
            $resolver,
            $validator,
            new PromptPackageFactory,
            new PromptRenderer,
        );

        $prompt = $factory->buildForCurrentUser();

        expect($prompt)->toBeString()
            ->and($prompt)->not->toContain('missing_lara_extension');
    } finally {
        config()->set('ai.lara.prompt.extension_path', null);
    }
});
