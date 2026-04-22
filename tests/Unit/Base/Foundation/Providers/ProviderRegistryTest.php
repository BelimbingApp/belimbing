<?php

use App\Base\Foundation\Providers\ProviderRegistry;
use Illuminate\Support\ServiceProvider;
use Tests\TestCase;

uses(TestCase::class);

if (! class_exists('ProviderRegistryPriorityTestProvider')) {
    class ProviderRegistryPriorityTestProvider extends ServiceProvider {}
}

if (! class_exists('ProviderRegistryAppTestProvider')) {
    class ProviderRegistryAppTestProvider extends ServiceProvider {}
}

it('normalizes mixed path separators when resolving extension providers', function (): void {
    $method = new ReflectionMethod(ProviderRegistry::class, 'extensionClassFromPath');
    $method->setAccessible(true);

    $basePath = str_replace('/', '\\', base_path('extensions'));
    $path = $basePath.'/sb-group\\qac/ServiceProvider.php';

    expect($method->invoke(null, $path))
        ->toBe('Extensions\\SbGroup\\Qac\\ServiceProvider');
});

it('resolves providers with priorities first, app providers last, and duplicates removed', function (): void {
    $resolved = ProviderRegistry::resolve(
        appProviders: [
            ProviderRegistryAppTestProvider::class,
            ProviderRegistryPriorityTestProvider::class,
        ],
        priorityProviders: [
            ProviderRegistryPriorityTestProvider::class,
        ],
    );

    expect($resolved[0])->toBe(ProviderRegistryPriorityTestProvider::class)
        ->and($resolved[array_key_last($resolved)])->toBe(ProviderRegistryAppTestProvider::class)
        ->and(array_values(array_filter(
            $resolved,
            static fn (string $provider): bool => $provider === ProviderRegistryPriorityTestProvider::class
        )))->toHaveCount(1);
});
