<?php

use App\Base\Foundation\Enums\BlbErrorCode;
use App\Base\Foundation\Exceptions\BlbConfigurationException;
use App\Base\Foundation\Exceptions\BlbIntegrationException;
use App\Modules\Core\AI\Services\LaraCapabilityMatcher;
use App\Modules\Core\AI\Services\LaraContextProvider;
use App\Modules\Core\AI\Services\LaraPromptFactory;
use Tests\TestCase;

uses(TestCase::class);

it('throws integration exception when Lara runtime context cannot be encoded', function (): void {
    $resource = fopen('php://memory', 'r');
    expect($resource)->not->toBeFalse();

    $contextProvider = Mockery::mock(LaraContextProvider::class);
    $contextProvider->shouldReceive('contextForCurrentUser')->once()->andReturn([
        'broken' => $resource,
    ]);

    $capabilityMatcher = Mockery::mock(LaraCapabilityMatcher::class);
    $capabilityMatcher->shouldReceive('discoverDelegableWorkersForCurrentUser')->once()->andReturn([]);

    $factory = new LaraPromptFactory($contextProvider, $capabilityMatcher);

    expect(fn () => $factory->buildForCurrentUser())
        ->toThrow(function (BlbIntegrationException $exception): void {
            expect($exception->reasonCode)->toBe(BlbErrorCode::LARA_PROMPT_CONTEXT_ENCODE_FAILED);
        });

    fclose($resource);
});

it('throws configuration exception when Lara prompt resource is missing', function (): void {
    $promptPath = app_path('Modules/Core/AI/Resources/lara/system_prompt.md');
    $backupPath = $promptPath.'.bak-test';

    rename($promptPath, $backupPath);

    try {
        $contextProvider = Mockery::mock(LaraContextProvider::class);
        $contextProvider->shouldReceive('contextForCurrentUser')->once()->andReturn([
            'app' => ['name' => 'Belimbing'],
        ]);

        $capabilityMatcher = Mockery::mock(LaraCapabilityMatcher::class);
        $capabilityMatcher->shouldReceive('discoverDelegableWorkersForCurrentUser')->once()->andReturn([]);

        $factory = new LaraPromptFactory($contextProvider, $capabilityMatcher);

        expect(fn () => $factory->buildForCurrentUser())
            ->toThrow(function (BlbConfigurationException $exception): void {
                expect($exception->reasonCode)->toBe(BlbErrorCode::LARA_PROMPT_RESOURCE_MISSING);
            });
    } finally {
        rename($backupPath, $promptPath);
    }
});

it('throws configuration exception when configured Lara prompt extension is missing', function (): void {
    config()->set('ai.lara.prompt.extension_path', 'storage/app/testing/missing_lara_extension.md');

    try {
        $contextProvider = Mockery::mock(LaraContextProvider::class);
        $contextProvider->shouldReceive('contextForCurrentUser')->once()->andReturn([
            'app' => ['name' => 'Belimbing'],
        ]);

        $capabilityMatcher = Mockery::mock(LaraCapabilityMatcher::class);
        $capabilityMatcher->shouldReceive('discoverDelegableWorkersForCurrentUser')->once()->andReturn([]);

        $factory = new LaraPromptFactory($contextProvider, $capabilityMatcher);

        expect(fn () => $factory->buildForCurrentUser())
            ->toThrow(function (BlbConfigurationException $exception): void {
                expect($exception->reasonCode)->toBe(BlbErrorCode::LARA_PROMPT_RESOURCE_MISSING)
                    ->and($exception->context['resource'] ?? null)->toBe('extension');
            });
    } finally {
        config()->set('ai.lara.prompt.extension_path', null);
    }
});
