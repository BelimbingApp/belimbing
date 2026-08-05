<?php

use App\Base\Foundation\Providers\ProviderRegistry;
use Tests\TestCase;

uses(TestCase::class);

it('normalizes mixed path separators when resolving extension providers', function (): void {
    $method = new ReflectionMethod(ProviderRegistry::class, 'extensionClassFromPath');
    $method->setAccessible(true);

    $basePath = str_replace('/', '\\', base_path('extensions'));
    $path = $basePath.'/sb-group\\qac/ServiceProvider.php';

    expect($method->invoke(null, $path))
        ->toBe('Extensions\\SbGroup\\Qac\\ServiceProvider');
});

it('resolves Base providers before module providers', function (): void {
    $resolved = ProviderRegistry::resolve();

    $basePositions = array_keys(array_filter(
        $resolved,
        static fn (string $provider): bool => str_starts_with($provider, 'App\\Base\\'),
    ));

    $modulePositions = array_keys(array_filter(
        $resolved,
        static fn (string $provider): bool => str_starts_with($provider, 'App\\Modules\\'),
    ));

    expect($basePositions)->not->toBeEmpty()
        ->and($modulePositions)->not->toBeEmpty()
        ->and(max($basePositions))->toBeLessThan(min($modulePositions));
});

it('discovers every provider from an owning component, with no app-level escape hatch', function (): void {
    $orphans = array_values(array_filter(
        ProviderRegistry::resolve(),
        static fn (string $provider): bool => ! str_starts_with($provider, 'App\\Base\\')
            && ! str_starts_with($provider, 'App\\Modules\\')
            && ! str_starts_with($provider, 'Extensions\\'),
    ));

    expect($orphans)->toBeEmpty();
});
